<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Receipt;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HeadOfficeOverviewService
{
    /** @return array<string, mixed> */
    public function build(Request $request, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $concessionaireId = null): array
    {
        abort_unless($request->user()->isHeadOffice(), 403);

        return DB::transaction(function () use ($request, $from, $to, $concessionaireId): array {
            $now = now();
            $monthStart = $from?->copy()->startOfDay() ?? $now->copy()->startOfMonth();
            $periodEnd = $to?->copy()->endOfDay() ?? $now;
            $company = $request->user()->dealership;
            abort_unless($company?->organization_type === 'head_office', 403);
            $branches = Client::where('dealership_id', $company->id)->where('customer_kind', 'dealership')->when($concessionaireId, fn (Builder $query) => $query->whereKey($concessionaireId))->orderBy('name')->get();
            $ids = $branches->modelKeys();
            $portfolio = fn (Builder $query): Builder => $query->where('dealership_id', $company->id)->where('customer_kind', 'person')->whereIn('concessionaire_id', $ids);
            $sales = Order::where('dealership_id', $company->id)->whereHas('client', $portfolio)->where('created_at', '<=', $now)->where('cancelled', false)
                ->with(['client:id,concessionaire_id', 'payments', 'installments.allocations.payment'])->get()->groupBy('client.concessionaire_id');
            $receipts = Receipt::where('dealership_id', $company->id)->whereHas('client', $portfolio)->where('approval_status', 'approved')->where('approval_status', 'approved')->whereNull('voided_at')->where('created_at', '<=', $now)
                ->with(['client:id,concessionaire_id', 'applications'])->get()->groupBy('client.concessionaire_id');
            $clients = Client::where('dealership_id', $company->id)->whereIn('concessionaire_id', $ids)->where('customer_kind', 'person')->selectRaw('concessionaire_id, count(*) as total')->groupBy('concessionaire_id')->get()->keyBy('concessionaire_id');
            $rows = $branches->map(function (Client $branch) use ($sales, $receipts, $clients, $monthStart, $periodEnd, $now): array {
                $orders = $sales->get($branch->id, collect());
                $money = $receipts->get($branch->id, collect());
                $pending = $orders->filter(fn (Order $order): bool => $order->balance_cents > 0);

                return [
                    'id' => $branch->id, 'name' => $branch->name, 'code' => $branch->document, 'active' => $branch->active,
                    'clients' => (int) ($clients->get($branch->id)?->total ?? 0),
                    'sales_month' => $orders->filter(fn (Order $order): bool => $order->created_at->betweenIncluded($monthStart, $periodEnd))->count(),
                    'sold_cents' => (int) $orders->filter(fn (Order $order): bool => $order->created_at->betweenIncluded($monthStart, $periodEnd))->sum(fn (Order $order): int => Money::cents($order->total)),
                    'received_cents' => (int) $money->filter(fn (Receipt $receipt): bool => $receipt->created_at->betweenIncluded($monthStart, $periodEnd))->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount)),
                    'debt_cents' => (int) $pending->sum('balance_cents'),
                    'overdue_cents' => (int) $pending->flatMap->installments->filter(fn ($installment): bool => $installment->due_date->lt($now->copy()->startOfDay()) && $installment->remaining_cents > 0)->sum('remaining_cents'),
                    'credit_cents' => (int) $money->sum('available_cents'),
                ];
            });

            return ['company' => $company, 'rows' => $rows, 'updatedAt' => $now, 'monthStart' => $monthStart];
        });
    }
}
