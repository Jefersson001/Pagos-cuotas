<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Services\Audit;
use App\Services\DealershipContext;
use App\Services\ReceiptService;
use App\Support\Money;
use App\Support\MonthlyDateFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $r->validate(['q' => 'nullable|string|max:120', 'state' => 'nullable|in:all,pending,rejected,credit,partial,applied,void', 'client_id' => 'nullable|integer', 'concessionaire_id' => 'nullable|integer']);
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
            $receiptId = preg_match('/^(?:REC[- ]*)?0*([1-9][0-9]*)$/i', trim($r->input('q')), $matches) ? $matches[1] : null;
            $query->where(function (Builder $matches) use ($term, $receiptId): void {
                $matches->where('reference', 'like', $term)->orWhereHas('client', fn (Builder $clients) => $clients->where('name', 'like', $term)->orWhere('document', 'like', $term));
                if ($receiptId !== null) {
                    $matches->orWhere('id', $receiptId);
                }
            });
        }
        $confirmed = (clone $query)->whereNull('voided_at')->where('approval_status', 'approved');
        $received = (int) (clone $confirmed)->toBase()->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) as cents')->value('cents');
        $pending = (int) (clone $query)->whereNull('voided_at')->where('approval_status', 'pending')->toBase()->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) as cents')->value('cents');
        $applied = (int) Payment::whereIn('receipt_id', (clone $confirmed)->select('receipts.id'))->whereNull('voided_at')->toBase()->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) as cents')->value('cents');
        $credit = $received - $applied;
        $state = $r->input('state');
        if ($state === 'void') {
            $query->whereNotNull('voided_at');
        } elseif (in_array($state, ['pending', 'rejected'], true)) {
            $query->whereNull('voided_at')->where('approval_status', $state);
        } elseif (in_array($state, ['credit', 'partial', 'applied'], true)) {
            $appliedSql = 'ROUND(COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.receipt_id = receipts.id AND payments.voided_at IS NULL), 0), 2)';
            $query->whereNull('voided_at')->where('approval_status', 'approved');
            $query->whereRaw($appliedSql.($state === 'applied' ? ' = receipts.amount' : ' < receipts.amount'));
            if ($state === 'partial') {
                $query->whereRaw($appliedSql.' > 0');
            }
        }
        $receipts = $query->latest()->orderByDesc('id')->paginate(20)->withQueryString();

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
        $v = $r->validate(['payment_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'token' => 'required|uuid', 'client_id' => 'required|integer', 'amount' => 'required|string', 'payment_method_id' => 'required|integer', 'order_id' => 'nullable|integer', 'installment_id' => 'nullable|integer', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        $v += $r->validate(['proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $receipt = $service->withClientProof($r->file('proof'), fn (?string $path): Receipt => DB::transaction(function () use ($r, $v, $service, $path) {
            $receipt = $service->receive($r->user(), $v['client_id'], array_merge($v, ['source_order_id' => $v['order_id'] ?? null, 'proof_path' => $path]));
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
        }, 3));

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
        $receipt->load(['client.concessionaire', 'method', 'recorder', 'approver', 'rejecter', 'voider', 'sourceOrder', 'intendedInstallment', 'applications.order', 'applications.recorder', 'applications.allocations.installment']);
        $orders = $receipt->client->orders()->where('cancelled', false)->with(['payments', 'installments.allocations.payment'])->get()->filter(fn ($o) => $o->balance_cents > 0);
        $approvalPreview = app(ReceiptService::class)->approvalPreview($receipt);
        $duplicateReference = filled($receipt->reference) && Receipt::where('dealership_id', $receipt->dealership_id)->where('payment_method_id', $receipt->payment_method_id)->where('reference', $receipt->reference)->where('id', '!=', $receipt->id)->whereNull('voided_at')->where('approval_status', '!=', 'rejected')->exists();

        return view('receipts.show', compact('receipt', 'orders', 'editing', 'approvalPreview', 'duplicateReference'));
    }

    public function update(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('update', $receipt);
        $values = $request->validate(['payment_date' => 'sometimes|required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        DB::transaction(function () use ($request, $receipt, $values): void {
            Client::lockForUpdate()->findOrFail($receipt->client_id);
            $locked = Receipt::lockForUpdate()->findOrFail($receipt->id);
            Gate::authorize('update', $locked);
            DealershipContext::forWrite($request->user(), null, $locked->dealership_id);
            abort_if($locked->voided_at, 422, 'Un pago anulado conserva sus datos originales.');
            if (isset($values['payment_date']) && $locked->approval_status !== 'pending' && $values['payment_date'] !== $locked->payment_date?->toDateString()) {
                throw ValidationException::withMessages(['payment_date' => 'La fecha de pago solo se puede corregir mientras esté pendiente de aprobación.']);
            }
            $before = $locked->only(['payment_date', 'reference', 'notes']);
            $locked->update($values);
            Audit::log($request->user()->id, 'save', 'receipt', $locked->id, ['before' => $before, 'after' => $locked->only(['payment_date', 'reference', 'notes'])]);
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
        Gate::authorize('review', $receipt);
        $values = $request->validate(['expected_applied_cents' => 'nullable|integer|min:0', 'approval_proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $service->approve($request->user(), $receipt, isset($values['expected_applied_cents']) ? (int) $values['expected_applied_cents'] : null, $request->file('approval_proof'));

        return back()->with('success', 'Pago aprobado. Se aplicó a la venta indicada y el importe restante quedó como saldo a favor del cliente.');
    }

    public function reject(Request $request, Receipt $receipt, ReceiptService $service): RedirectResponse
    {
        Gate::authorize('review', $receipt);
        $values = $request->validate(['reason' => 'required|string|min:10|max:1000']);
        $service->reject($request->user(), $receipt, $values['reason']);

        return back()->with('success', 'Pago rechazado. Se conserva el registro y el motivo; no modifica los saldos.');
    }

    public function attachProof(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('update', $receipt);
        abort_unless($receipt->approval_status === 'pending' && ! $receipt->voided_at, 422);
        $request->validate(['proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $path = $request->file('proof')->store('receipt-proofs', 'local');
        $oldPath = null;
        try {
            DB::transaction(function () use ($request, $receipt, $path, &$oldPath): void {
                Client::lockForUpdate()->findOrFail($receipt->client_id);
                $locked = Receipt::lockForUpdate()->findOrFail($receipt->id);
                Gate::authorize('update', $locked);
                DealershipContext::forWrite($request->user(), null, $locked->dealership_id);
                abort_unless($locked->approval_status === 'pending' && ! $locked->voided_at, 422);
                $oldPath = $locked->proof_path;
                $locked->update(['proof_path' => $path]);
                Audit::log($request->user()->id, 'attach_proof', 'receipt', $locked->id);
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Comprobante adjuntado. Casa matriz puede revisarlo antes de aprobar.');
    }

    public function proof(Request $request, Receipt $receipt): StreamedResponse
    {
        Gate::authorize('view', $receipt);

        return $this->proofResponse($request, $receipt->proof_path, 'REC-'.$receipt->id);
    }

    public function approvalProof(Request $request, Receipt $receipt): StreamedResponse
    {
        Gate::authorize('view', $receipt);
        abort_if($request->user()->isCustomer(), 403);

        return $this->proofResponse($request, $receipt->approval_proof_path, 'REC-'.$receipt->id.'-casa-matriz');
    }

    private function proofResponse(Request $request, ?string $path, string $filename): StreamedResponse
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $filename .= '.'.pathinfo($path, PATHINFO_EXTENSION);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('preview')
            ? Storage::disk('local')->response($path, $filename, $headers, 'inline')
            : Storage::disk('local')->download($path, $filename, $headers);
    }
}
