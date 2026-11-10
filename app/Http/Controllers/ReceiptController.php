<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Services\Audit;
use App\Services\DealershipContext;
use App\Services\ReceiptService;
use App\Support\Money;
use App\Support\MonthlyDateFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    public function index(Request $r)
    {
        if ($r->user()->isHeadOffice() && ! $r->has('state')) {
            $r->merge(['state' => 'pending']);
        }
        if ($r->input('state') === 'pending' && ! $r->filled('from') && ! $r->filled('to')) {
            $oldest = Receipt::visibleTo($r->user())->where('approval_status', 'pending')->whereNull('voided_at')->min('created_at');
            $r->merge(['from' => $oldest ? substr($oldest, 0, 10) : today()->toDateString(), 'to' => today()->toDateString(), 'date_mode' => 'custom']);
        }
        MonthlyDateFilter::apply($r);
        $r->validate(['q' => 'nullable|string|max:120', 'state' => 'nullable|in:pending,rejected,credit,partial,applied,void', 'client_id' => 'nullable|integer', 'concessionaire_id' => 'nullable|integer']);
        $query = Receipt::visibleTo($r->user())->with(['client.concessionaire', 'method', 'applications', 'dealership', 'recorder']);
        if ($r->filled('concessionaire_id')) {
            $query->whereHas('client', fn ($clients) => $clients->where('concessionaire_id', $r->integer('concessionaire_id')));
        }
        $concessionaires = $r->user()->isHeadOffice() ? Client::where('dealership_id', $r->user()->dealership_id)->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name']) : collect();
        $query->whereDate('created_at', '>=', $r->input('from'))->whereDate('created_at', '<=', $r->input('to'));
        if ($r->filled('client_id')) {
            $query->where('client_id', $r->input('client_id'));
        }
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('id', $r->input('q'))->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('document', 'like', $term)));
        }
        $receipts = $query->latest()->get();
        $received = $receipts->whereNull('voided_at')->where('approval_status', 'approved')->sum(fn ($p) => Money::cents($p->amount));
        $pending = $receipts->whereNull('voided_at')->where('approval_status', 'pending')->sum(fn ($p) => Money::cents($p->amount));
        $credit = $receipts->sum('available_cents');
        $receipts = $receipts->filter(fn ($p) => match ($r->input('state')) {
            'pending' => ! $p->voided_at && $p->approval_status === 'pending', 'rejected' => ! $p->voided_at && $p->approval_status === 'rejected', 'credit' => $p->available_cents > 0,'partial' => $p->status === 'Parcialmente aplicado','applied' => ! $p->voided_at && $p->approval_status === 'approved' && $p->available_cents === 0,'void' => (bool) $p->voided_at,default => true
        });
        $page = max(1, $r->integer('page', 1));
        $receipts = new LengthAwarePaginator($receipts->values()->forPage($page, 20), $receipts->count(), 20, $page, ['path' => $r->url(), 'query' => $r->query()]);

        return view('receipts.index', compact('receipts', 'received', 'credit', 'pending', 'concessionaires'));
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
            $receipt = $service->receive($r->user(), $v['client_id'], array_merge($v, ['source_order_id' => $v['order_id'] ?? null]));
            if ($receipt->approval_status === 'approved' && ! empty($v['order_id']) && ! $receipt->applications()->where('token', $v['token'])->exists()) {
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

        return redirect()->route('receipts.show', $receipt)->with('success', $receipt->approval_status === 'pending' ? 'Pago registrado, pendiente de aprobación por casa matriz. Los saldos todavía no cambian.' : 'Pago registrado. El importe sin aplicar queda como saldo a favor del cliente.');
    }

    public function show(Receipt $receipt): View
    {
        return $this->detail($receipt, false);
    }

    public function edit(Receipt $receipt): View
    {
        Gate::authorize('update', $receipt);

        return $this->detail($receipt, true);
    }

    private function detail(Receipt $receipt, bool $editing): View
    {
        Gate::authorize('view', $receipt);
        $receipt->load(['client.concessionaire', 'method', 'recorder', 'approver', 'sourceOrder', 'intendedInstallment', 'applications.order', 'applications.recorder', 'applications.allocations.installment']);
        $orders = $receipt->client->orders()->where('cancelled', false)->with(['payments', 'installments.allocations.payment'])->get()->filter(fn ($o) => $o->balance_cents > 0);

        return view('receipts.show', compact('receipt', 'orders', 'editing'));
    }

    public function update(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('update', $receipt);
        $values = $request->validate(['reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        DB::transaction(function () use ($request, $receipt, $values): void {
            Client::lockForUpdate()->findOrFail($receipt->client_id);
            $locked = Receipt::lockForUpdate()->findOrFail($receipt->id);
            Gate::authorize('update', $locked);
            DealershipContext::forWrite($request->user(), null, $locked->dealership_id);
            abort_if($locked->voided_at, 422, 'Un pago anulado conserva sus datos originales.');
            $before = $locked->only(['reference', 'notes']);
            $locked->update($values);
            Audit::log($request->user()->id, 'save', 'receipt', $locked->id, ['before' => $before, 'after' => $locked->only(['reference', 'notes'])]);
        }, 3);

        return redirect()->route('receipts.show', $receipt)->with('success', 'Datos del pago actualizados.');
    }

    public function apply(Request $r, Receipt $receipt, ReceiptService $service)
    {
        Gate::authorize('apply', $receipt);
        $v = $r->validate(['token' => 'required|uuid', 'order_id' => 'required|integer', 'amount' => 'required|string', 'installment_id' => 'nullable|integer']);
        $service->apply($r->user(), $receipt, $v['order_id'], $v);

        return back()->with('success', 'Pago aplicado a la venta. Los saldos se actualizaron.');
    }

    public function void(Request $r, Receipt $receipt, ReceiptService $service)
    {
        $v = $r->validate(['reason' => 'required|string|min:10|max:1000']);
        $service->void($r->user(), $receipt, $v['reason']);

        return back()->with('success', 'Ingreso anulado. Se restauraron las deudas y se retiró su saldo a favor.');
    }

    public function approve(Request $request, Receipt $receipt, ReceiptService $service): RedirectResponse
    {
        $service->approve($request->user(), $receipt);

        return back()->with('success', 'Pago aprobado. Se aplicó a la venta indicada y el importe restante quedó como saldo a favor del cliente.');
    }

    public function reject(Request $request, Receipt $receipt, ReceiptService $service): RedirectResponse
    {
        Gate::authorize('review', $receipt);
        $values = $request->validate(['reason' => 'required|string|min:10|max:1000']);
        $service->reject($request->user(), $receipt, $values['reason']);

        return back()->with('success', 'Pago rechazado. Se conserva el registro y el motivo; no modifica los saldos.');
    }
}
