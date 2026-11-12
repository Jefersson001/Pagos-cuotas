<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Services\Audit;
use App\Services\DealershipContext;
use App\Services\ReceiptService;
use App\Services\SalesService;
use App\Support\MonthlyDateFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function headOfficeOrders(Request $request): View
    {
        abort_unless($request->user()->isHeadOffice(), 403);
        MonthlyDateFilter::apply($request);
        $concessionaires = Client::where('dealership_id', $request->user()->dealership_id)->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name']);
        $request->validate([
            'q' => 'nullable|string|max:255',
            'concessionaire_id' => ['nullable', 'integer', Rule::in($concessionaires->modelKeys())],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('dealership_id', $request->user()->dealership_id)->where('customer_kind', 'person')->whereIn('concessionaire_id', $concessionaires->modelKeys())],
        ]);
        $query = Order::headOfficePortfolio($request->user())->with(['client.concessionaire', 'dealership', 'payments', 'installments.allocations.payment'])
            ->whereDate('created_at', '>=', $request->input('from'))->whereDate('created_at', '<=', $request->input('to'));
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('concessionaire_id')) {
            $query->whereHas('client', fn (Builder $clients) => $clients->where('concessionaire_id', $request->integer('concessionaire_id')));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(fn (Builder $orders) => $orders->where('description', 'like', $term)->orWhere('id', $request->input('q'))
                ->orWhereHas('client', fn (Builder $clients) => $clients->where('name', 'like', $term)->orWhere('document', 'like', $term)));
        }

        return view('orders.index', [
            'orders' => $query->latest()->paginate(15)->withQueryString(),
            'readOnlyPortfolio' => true, 'concessionaires' => $concessionaires,
            'clients' => Client::headOfficeCustomers($request->user())->with('concessionaire:id,name')->orderBy('name')->get(['id', 'name', 'document', 'concessionaire_id']),
        ]);
    }

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
            $products = Product::where('dealership_id', $client->dealership_id)->where('active', true)->where('is_freight', false)->whereIn('id', $ids)->get()->keyBy('id');
            foreach (is_array($oldItems) ? $oldItems : [] as $item) {
                if (! is_array($item) || ! is_scalar($item['product_id'] ?? null)) {
                    continue;
                }
                $product = $products->get($item['product_id'] ?? null);
                if ($product) {
                    $customAmounts = in_array($item['use_custom_amounts'] ?? false, [true, 1, '1'], true);
                    $unitPrice = $customAmounts && is_scalar($item['unit_price'] ?? null) ? (string) $item['unit_price'] : $product->price;
                    $initialAmount = $customAmounts && is_scalar($item['initial_amount'] ?? null) ? (string) $item['initial_amount'] : $product->required_initial_amount;
                    if (! $product->pay_in_installments) {
                        $initialAmount = '0.00';
                    }
                    $selectedItems[] = ['product_id' => $product->id, 'description' => $product->name, 'quantity' => max(1, min(100000, (int) ($item['quantity'] ?? 1))),
                        'unit_price' => $unitPrice, 'initial_amount' => $initialAmount, 'pay_in_installments' => $product->pay_in_installments,
                        'use_custom_amounts' => $customAmounts, 'isEditing' => $customAmounts, 'catalog_version' => $product->catalog_version];
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
        $v = $r->validate(['token' => 'required|uuid', 'client_id' => 'required|integer', 'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer|distinct', 'items.*.catalog_version' => 'nullable|string|size:64', 'items.*.quantity' => 'required|integer|min:1|max:100000', 'items.*.use_custom_amounts' => 'sometimes|boolean', 'items.*.unit_price' => 'required_if:items.*.use_custom_amounts,1,true|nullable|string|regex:/^\d{1,10}(\.\d{1,2})?$/', 'items.*.initial_amount' => 'required_if:items.*.use_custom_amounts,1,true|nullable|string|regex:/^\d{1,10}(\.\d{1,2})?$/', 'installment_count' => 'required|integer|min:1|max:120', 'frequency' => 'required|in:weekly,fortnightly,monthly,first_half,second_half,custom', 'first_due' => 'required|date_format:Y-m-d', 'custom' => 'nullable|array|max:120', 'custom.*.amount' => 'required|regex:/^\d{1,10}(\.\d{1,2})?$/', 'custom.*.due_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:3000']);
        $order = $service->create($r->user(), $v);

        return redirect()->route('orders.show', $order)->with('success', 'Venta creada y cronograma registrado.');
    }

    public function show(Request $r, Order $order)
    {
        Gate::authorize('view', $order);
        $order->load(['client.receipts.applications', 'owner', 'items', 'payments.method', 'payments.recorder', 'installments.allocations.payment']);

        return view('orders.show', ['order' => $order, 'methods' => PaymentMethod::where('dealership_id', $order->dealership_id)->where('active', true)->get(),
            'freightProducts' => $r->user()->can('update', $order) && $order->canAddFreight() ? Product::where('dealership_id', $order->dealership_id)->where('active', true)->where('product_type', 'service')->where('is_freight', true)->orderBy('name')->get(['id', 'name', 'price']) : collect()]);
    }

    public function addFreight(Request $request, Order $order, SalesService $service): RedirectResponse
    {
        Gate::authorize('update', $order);
        $values = $request->validate(['freight_product_id' => 'required|integer', 'freight_amount' => 'required|string|regex:/^\d{1,10}(\.\d{1,2})?$/']);
        $service->addFreight($request->user(), $order, (int) $values['freight_product_id'], $values['freight_amount']);

        return redirect()->route('orders.show', $order)->with('success', 'Flete agregado. Su importe se guardó en esta venta con vencimiento junto a la última cuota.');
    }

    public function edit(Order $order): View
    {
        Gate::authorize('update', $order);
        $order->load(['client', 'payments', 'installments.allocations.payment']);

        return view('orders.edit', compact('order'));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('update', $order);
        $values = $request->validate(['notes' => 'nullable|string|max:3000']);
        DB::transaction(function () use ($request, $order, $values): void {
            Client::lockForUpdate()->findOrFail($order->client_id);
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            Gate::authorize('update', $locked);
            DealershipContext::forWrite($request->user(), null, $locked->dealership_id);
            $before = $locked->only('notes');
            $locked->update($values);
            Audit::log($request->user()->id, 'save', 'order', $locked->id, ['before' => $before, 'after' => $locked->only('notes')]);
        }, 3);

        return redirect()->route('orders.show', $order)->with('success', 'Venta actualizada.');
    }

    public function pay(Request $r, Order $order, SalesService $service, ReceiptService $receipts)
    {
        Gate::authorize('update', $order);
        $v = $r->validate(['token' => 'required|uuid', 'amount' => 'required|string', 'payment_source' => 'nullable|in:new,credit', 'new_payment' => 'nullable|boolean', 'allow_credit' => 'nullable|boolean', 'payment_method_id' => 'required_unless:payment_source,credit|nullable|integer', 'installment_id' => 'nullable|integer', 'collection_channel' => 'nullable|in:head_office,concessionaire', 'reference' => 'exclude_if:payment_source,credit|required|string|max:255', 'notes' => 'nullable|string|max:3000']);
        $v += $r->validate(['payment_date' => 'required_unless:payment_source,credit|nullable|date_format:Y-m-d|before_or_equal:today']);
        $v += $r->validate(['proof' => 'exclude_if:payment_source,credit|required|file|mimes:jpg,jpeg,png,pdf|max:5120'], ['proof.required' => 'Adjunta el comprobante del cliente para registrar el pago.']);
        $receipts->withClientProof($v['proof'] ?? null, fn (?string $path): Payment|Receipt => $service->pay($r->user(), $order->id, array_merge($v, ['proof_path' => $path])));

        return back()->with('success', ($v['payment_source'] ?? 'new') === 'credit' ? 'Saldo a favor aplicado a la venta. No se registró dinero nuevo.' : (($v['collection_channel'] ?? 'head_office') === 'concessionaire' ? 'Cobro registrado. Queda pendiente de incluirse en una remesa a casa matriz.' : ($r->user()->isAdmin() ? 'Pago registrado correctamente.' : 'Pago registrado y relacionado con esta venta, pendiente de aprobación por casa matriz.')));
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
