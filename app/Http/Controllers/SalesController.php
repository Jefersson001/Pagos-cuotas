<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\Audit;
use App\Services\DealershipContext;
use App\Services\SalesService;
use App\Support\MonthlyDateFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SalesController extends Controller
{
    public function orders(Request $r)
    {
        MonthlyDateFilter::apply($r);
        $q = Order::visibleTo($r->user())->with(['client', 'payments', 'installments.allocations.payment']);
        $q->whereDate('created_at', '>=', $r->input('from'))->whereDate('created_at', '<=', $r->input('to'));
        if ($r->filled('q')) {
            $term = $r->string('q')->toString();
            $q->where(fn ($q) => $q->where('description', 'like', "%$term%")->orWhere('id', $term)->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$term%")));
        }

        return view('orders.index', ['orders' => $q->latest()->paginate(15)->withQueryString()]);
    }

    public function create(Request $r)
    {
        $id = $r->old('client_id', $r->input('client_id'));
        $client = is_scalar($id) && ctype_digit((string) $id) ? Client::visibleTo($r->user())->where('active', true)->whereHas('dealership', fn ($q) => $q->where('active', true))->with('dealership')->find($id) : null;

        $selectedItems = [];
        if ($client) {
            $oldItems = $r->old('items', []);
            $ids = collect(is_array($oldItems) ? $oldItems : [])->pluck('product_id')->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id));
            $products = Product::where('dealership_id', $client->dealership_id)->where('active', true)->whereIn('id', $ids)->get()->keyBy('id');
            foreach (is_array($oldItems) ? $oldItems : [] as $item) {
                if (! is_array($item) || ! is_scalar($item['product_id'] ?? null)) {
                    continue;
                }
                $product = $products->get($item['product_id'] ?? null);
                if ($product) {
                    $selectedItems[] = ['product_id' => $product->id, 'description' => $product->name, 'quantity' => max(1, min(100000, (int) ($item['quantity'] ?? 1))), 'unit_price' => $product->price, 'initial_amount' => $product->initial_amount];
                }
            }
        }

        return view('orders.create', ['selectedClient' => $client ? $this->clientOption($client) : null, 'selectedItems' => $selectedItems, 'productSearchUrl' => route('products.options'), 'clientSearchUrl' => route('orders.client-options', ['dealership_id' => DealershipContext::id($r->user()) ?? ''])]);
    }

    public function clientOptions(Request $r): JsonResponse
    {
        $r->validate(['q' => 'nullable|string|max:120']);
        $query = Client::visibleTo($r->user())->where('active', true)->whereHas('dealership', fn ($q) => $q->where('active', true))->with('dealership');
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('document', 'like', $term));
        }
        $clients = $query->orderBy('name')->orderBy('id')->limit(16)->get();

        return response()->json(['clients' => $clients->take(15)->map(fn (Client $client) => $this->clientOption($client))->values(), 'has_more' => $clients->count() > 15])->header('Cache-Control', 'private, no-store');
    }

    private function clientOption(Client $client): array
    {
        return ['id' => $client->id, 'name' => $client->name, 'document' => $client->document, 'dealership_id' => $client->dealership_id, 'dealership_name' => $client->dealership->name];
    }

    public function store(Request $r, SalesService $service)
    {
        $v = $r->validate(['token' => 'required|uuid', 'client_id' => 'required|integer', 'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:100000', 'installment_count' => 'required|integer|min:1|max:120', 'frequency' => 'required|in:weekly,fortnightly,monthly,custom', 'first_due' => 'required|date_format:Y-m-d', 'custom' => 'nullable|array|max:120', 'custom.*.amount' => 'required|regex:/^\d{1,10}(\.\d{1,2})?$/', 'custom.*.due_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:3000']);
        $order = $service->create($r->user(), $v);

        return redirect()->route('orders.show', $order)->with('success', 'Venta creada y cronograma registrado.');
    }

    public function show(Request $r, Order $order)
    {
        Gate::authorize('view', $order);
        $order->load(['client.receipts.applications', 'owner', 'items', 'payments.method', 'payments.recorder', 'installments.allocations.payment']);

        return view('orders.show', ['order' => $order, 'methods' => PaymentMethod::where('dealership_id', $order->dealership_id)->where('active', true)->get()]);
    }

    public function pay(Request $r, Order $order, SalesService $service)
    {
        Gate::authorize('update', $order);
        $v = $r->validate(['token' => 'required|uuid', 'amount' => 'required|string', 'payment_source' => 'nullable|in:new,credit', 'new_payment' => 'nullable|boolean', 'allow_credit' => 'nullable|boolean', 'payment_method_id' => 'required_unless:payment_source,credit|nullable|integer', 'installment_id' => 'nullable|integer', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        $service->pay($r->user(), $order->id, $v);

        return back()->with('success', ($v['payment_source'] ?? 'new') === 'credit' ? 'Saldo a favor aplicado a la venta. No se registró dinero nuevo.' : 'Pago registrado correctamente.');
    }

    public function void(Request $r, Payment $payment, SalesService $service)
    {
        $v = $r->validate(['reason' => 'required|string|min:10|max:1000']);
        $service->void($r->user(), $payment, $v['reason']);

        return back()->with('success', 'Aplicación desvinculada. El importe queda disponible como saldo a favor del concesionario.');
    }

    public function cancel(Request $r, Order $order)
    {
        Gate::authorize('update', $order);
        $r->validate(['reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($r, $order) {
            $o = Order::lockForUpdate()->findOrFail($order->id);
            Gate::forUser($r->user())->authorize('update', $o);
            DealershipContext::forWrite($r->user(), null, $o->dealership_id);
            if ($o->paid_cents > 0) {
                throw ValidationException::withMessages(['reason' => 'Anule los pagos antes de cancelar el pedido.']);
            }$o->update(['cancelled' => true]);
            Audit::log($r->user()->id, 'cancel', 'order', $o->id, ['reason' => $r->reason]);
        });

        return back()->with('success', 'Pedido cancelado.');
    }
}
