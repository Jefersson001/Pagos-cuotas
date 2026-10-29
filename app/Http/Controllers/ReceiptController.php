<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Services\DealershipContext;
use App\Services\ReceiptService;
use App\Support\Money;
use App\Support\MonthlyDateFilter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReceiptController extends Controller
{
    public function index(Request $r)
    {
        MonthlyDateFilter::apply($r);
        $r->validate(['q' => 'nullable|string|max:120', 'state' => 'nullable|in:credit,partial,applied,void', 'client_id' => 'nullable|integer']);
        $query = Receipt::visibleTo($r->user())->with(['client', 'method', 'applications', 'dealership', 'recorder']);
        $query->whereDate('created_at', '>=', $r->input('from'))->whereDate('created_at', '<=', $r->input('to'));
        if ($r->filled('client_id')) {
            $query->where('client_id', $r->input('client_id'));
        }
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('id', $r->input('q'))->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('document', 'like', $term)));
        }
        $receipts = $query->latest()->get();
        $received = $receipts->whereNull('voided_at')->sum(fn ($p) => Money::cents($p->amount));
        $credit = $receipts->sum('available_cents');
        $receipts = $receipts->filter(fn ($p) => match ($r->input('state')) {
            'credit' => $p->available_cents > 0,'partial' => $p->status === 'Parcialmente aplicado','applied' => ! $p->voided_at && $p->available_cents === 0,'void' => (bool) $p->voided_at,default => true
        });
        $page = max(1, $r->integer('page', 1));
        $receipts = new LengthAwarePaginator($receipts->values()->forPage($page, 20), $receipts->count(), 20, $page, ['path' => $r->url(), 'query' => $r->query()]);

        return view('receipts.index', compact('receipts', 'received', 'credit'));
    }

    public function create(Request $r)
    {
        $client = Client::visibleTo($r->user())->where('active', true)->with('dealership')->find($r->old('client_id', $r->input('client_id')));
        $selectedClient = $client ? ['id' => $client->id, 'name' => $client->name, 'document' => $client->document, 'dealership_id' => $client->dealership_id, 'dealership_name' => $client->dealership->name] : null;
        $clientSearchUrl = route('orders.client-options', ['dealership_id' => DealershipContext::id($r->user()) ?? '']);

        return view('receipts.create', compact('selectedClient', 'clientSearchUrl'));
    }

    public function account(Request $r)
    {
        $r->validate(['client_id' => 'required|integer']);
        $client = Client::findOrFail($r->input('client_id'));
        Gate::authorize('view', $client);
        $orders = $client->orders()->with(['payments', 'installments.allocations.payment'])->where('cancelled', false)->get()->filter(fn ($o) => $o->balance_cents > 0);

        return response()->json(['credit' => $client->credit_cents, 'debt' => $orders->sum('balance_cents'), 'methods' => PaymentMethod::where('dealership_id', $client->dealership_id)->where('active', true)->get(['id', 'name']), 'orders' => $orders->map(fn ($o) => ['id' => $o->id, 'description' => $o->description, 'balance' => $o->balance_cents, 'installments' => $o->installments->filter(fn ($i) => $i->remaining_cents > 0)->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'label' => $i->label, 'remaining' => $i->remaining_cents])->values()])->values()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, ReceiptService $service)
    {
        $v = $r->validate(['token' => 'required|uuid', 'client_id' => 'required|integer', 'amount' => 'required|string', 'payment_method_id' => 'required|integer', 'order_id' => 'nullable|integer', 'installment_id' => 'nullable|integer', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        $receipt = DB::transaction(function () use ($r, $v, $service) {
            $receipt = $service->receive($r->user(), $v['client_id'], $v);
            if (! empty($v['order_id']) && ! $receipt->applications()->where('token', $v['token'])->exists()) {
                $order = Order::findOrFail($v['order_id']);
                Gate::authorize('update', $order);
                $limit = $order->balance_cents;
                if (! empty($v['installment_id'])) {
                    $limit = $order->installments->firstWhere('id', (int) $v['installment_id'])?->remaining_cents ?? 0;
                }
                $service->apply($r->user(), $receipt, $order->id, array_merge($v, ['amount' => Money::decimal(min(Money::cents($v['amount']), $limit))]));
            }

            return $receipt;
        }, 3);

        return redirect()->route('receipts.show', $receipt)->with('success', 'Pago registrado. El importe sin aplicar queda como saldo a favor del concesionario.');
    }

    public function show(Receipt $receipt)
    {
        Gate::authorize('view', $receipt);
        $receipt->load(['client', 'method', 'recorder', 'applications.order', 'applications.recorder', 'applications.allocations.installment']);
        $orders = $receipt->client->orders()->where('cancelled', false)->with(['payments', 'installments.allocations.payment'])->get()->filter(fn ($o) => $o->balance_cents > 0);

        return view('receipts.show', compact('receipt', 'orders'));
    }

    public function apply(Request $r, Receipt $receipt, ReceiptService $service)
    {
        Gate::authorize('update', $receipt);
        $v = $r->validate(['token' => 'required|uuid', 'order_id' => 'required|integer', 'amount' => 'required|string', 'installment_id' => 'nullable|integer']);
        $service->apply($r->user(), $receipt, $v['order_id'], $v);

        return back()->with('success', 'Pago aplicado a la venta. Los saldos se actualizaron.');
    }

    public function void(Request $r, Receipt $receipt, ReceiptService $service)
    {
        Gate::authorize('update', $receipt);
        $v = $r->validate(['reason' => 'required|string|min:10|max:1000']);
        $service->void($r->user(), $receipt, $v['reason']);

        return back()->with('success', 'Ingreso anulado. Se restauraron las deudas y se retiró su saldo a favor.');
    }
}
