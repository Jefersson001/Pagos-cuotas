<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Support\Money;
use App\Support\OverviewPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductOverviewService
{
    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            $now = now();
            $period = OverviewPeriod::fromRequest($request);
            $monthStart = $period->from;
            $canFilterConcessionaires = $request->user()->isAdmin() || $request->user()->isHeadOffice();
            $concessionaires = $canFilterConcessionaires ? Client::visibleTo($request->user())->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name', 'dealership_id']) : collect();
            if ($canFilterConcessionaires) {
                $request->validate(['concessionaire_id' => ['nullable', 'integer', Rule::in($concessionaires->modelKeys())]]);
            }
            $products = Product::visibleTo($request->user())->orderBy('name')->get();
            $visibleProducts = Product::visibleTo($request->user())->select('products.id');
            $visibleOrders = Order::query();
            if ($request->user()->isHeadOffice()) {
                $companyId = $request->user()->dealership_id;
                $visibleOrders->where('dealership_id', $companyId)->whereHas('client', fn (Builder $clients): Builder => $clients
                    ->where('dealership_id', $companyId)->where('customer_kind', 'person')
                    ->whereHas('concessionaire', fn (Builder $dealers): Builder => $dealers->where('dealership_id', $companyId)->where('customer_kind', 'dealership')));
            } else {
                $visibleOrders->visibleTo($request->user());
            }
            if ($canFilterConcessionaires && $request->filled('concessionaire_id')) {
                $selectedDealer = $concessionaires->firstWhere('id', $request->integer('concessionaire_id'));
                $visibleOrders->where('dealership_id', $selectedDealer->dealership_id)->whereHas('client', fn (Builder $clients): Builder => $clients->where('customer_kind', 'person')->where('concessionaire_id', $selectedDealer->id)->where('dealership_id', $selectedDealer->dealership_id));
            }
            $visibleOrders->where('cancelled', false)->where('created_at', '<=', $now)->select('orders.id');
            $items = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.product_id', $visibleProducts)->whereIn('orders.id', $visibleOrders);
            $sales = (clone $items)->select('order_items.product_id')
                ->selectRaw('SUM(quantity) AS units_total')
                ->selectRaw('MAX(orders.created_at) AS last_sale')
                ->selectRaw('SUM(CASE WHEN orders.created_at BETWEEN ? AND ? THEN quantity ELSE 0 END) AS units_month', [$monthStart, $period->to])
                ->selectRaw('SUM(CASE WHEN orders.created_at BETWEEN ? AND ? THEN ROUND(unit_price * 100) * quantity ELSE 0 END) AS sold_month_cents', [$monthStart, $period->to])
                ->groupBy('order_items.product_id')->get()->keyBy('product_id');
            $rows = $products->map(function (Product $product) use ($sales): array {
                $sale = $sales->get($product->id);

                return ['product' => $product, 'units_total' => (int) ($sale?->units_total ?? 0), 'units_month' => (int) ($sale?->units_month ?? 0), 'sold_month_cents' => (int) ($sale?->sold_month_cents ?? 0), 'last_sale' => $sale?->last_sale];
            });
            $active = $products->where('active', true);
            $financed = $active->filter(fn (Product $product): bool => $product->pay_in_installments && Money::cents($product->price) > Money::cents($product->initial_amount));
            $mostUsedPlan = $financed->countBy(fn (Product $product): int => $product->installment_count ?? 5)->sortDesc();
            $types = collect(['general' => 'Productos generales', 'vehicle' => 'Vehículos', 'service' => 'Servicios'])->map(function (string $label, string $type) use ($products): array {
                $members = $products->filter(fn (Product $product): bool => ($product->product_type ?? 'general') === $type);

                return ['label' => $label, 'total' => $members->count(), 'active' => $members->where('active', true)->count()];
            });
            $dailySales = (clone $items)->whereBetween('orders.created_at', [$monthStart, $period->to])
                ->selectRaw('DATE(orders.created_at) AS sale_day, SUM(ROUND(unit_price * 100) * quantity) AS amount')
                ->groupByRaw('DATE(orders.created_at)')->get();
            $weeks = $period->buckets()->map(function (array $bucket) use ($dailySales): array {
                $from = $bucket['from'];
                $to = $bucket['to'];

                return ['label' => $bucket['label'], 'amount' => (int) $dailySales->filter(fn ($sale): bool => $sale->sale_day >= $from->toDateString() && $sale->sale_day <= $to->toDateString())->sum('amount')];
            });
            $withoutMovement = $rows->filter(fn (array $row): bool => $row['product']->active && $row['units_month'] === 0);
            $ranking = $rows->where('units_month', '>', 0)->sort(function (array $left, array $right): int {
                return ($right['units_month'] <=> $left['units_month']) ?: ($right['sold_month_cents'] <=> $left['sold_month_cents']) ?: strcasecmp($left['product']->name, $right['product']->name);
            })->take(5)->values();

            return [
                'updatedAt' => $now, 'monthStart' => $monthStart, 'total' => $products->count(), 'active' => $active->count(),
                'newThisMonth' => $products->filter(fn (Product $product): bool => $period->contains($product->created_at))->count(),
                'unitsMonth' => (int) $rows->sum('units_month'), 'soldMonthCents' => (int) $rows->sum('sold_month_cents'),
                'sellingProducts' => $rows->where('units_month', '>', 0)->count(),
                'ordersMonth' => (clone $items)->whereBetween('orders.created_at', [$monthStart, $period->to])->distinct()->count('orders.id'),
                'averagePriceCents' => $active->isEmpty() ? 0 : (int) round($active->avg(fn (Product $product): int => Money::cents($product->price))),
                'minPriceCents' => $active->isEmpty() ? 0 : $active->min(fn (Product $product): int => Money::cents($product->price)),
                'maxPriceCents' => $active->isEmpty() ? 0 : $active->max(fn (Product $product): int => Money::cents($product->price)),
                'averageInitialCents' => $financed->isEmpty() ? 0 : (int) round($financed->avg(fn (Product $product): int => Money::cents($product->initial_amount))),
                'cashCount' => $active->where('pay_in_installments', false)->count(),
                'installmentCount' => $active->where('pay_in_installments', true)->count(),
                'periodEnd' => $period->to, 'canFilterConcessionaires' => $canFilterConcessionaires, 'concessionaires' => $concessionaires,
                'financedCount' => $financed->count(), 'mostUsedPlan' => $mostUsedPlan->isEmpty() ? null : $mostUsedPlan->keys()->first(),
                'withoutImage' => $active->filter(fn (Product $product): bool => blank($product->image_path))->count(),
                'withoutSku' => $active->filter(fn (Product $product): bool => blank($product->sku))->count(),
                'neverSold' => $rows->filter(fn (array $row): bool => $row['product']->active && $row['units_total'] === 0)->count(),
                'withoutMovement' => $withoutMovement->count(), 'quietProducts' => $withoutMovement->sortBy('last_sale')->take(5)->values(),
                'types' => $types, 'ranking' => $ranking, 'weeks' => $weeks, 'maxWeekCents' => max(1, $weeks->max('amount') ?? 0),
            ];
        });
    }
}
