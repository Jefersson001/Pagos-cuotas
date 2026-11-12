<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Remittance;
use App\Models\User;
use App\Support\Money;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    /** @param Closure(?string): (Receipt|Payment) $register */
    public function withClientProof(?UploadedFile $proof, Closure $register): Receipt|Payment
    {
        Validator::make(['proof' => $proof], ['proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120'])->validate();
        $path = $proof?->store('receipt-proofs', 'local');
        if ($proof && ! $path) {
            throw ValidationException::withMessages(['proof' => 'No se pudo guardar el comprobante. Intenta adjuntarlo de nuevo.']);
        }
        $retainedPath = null;
        try {
            $result = $register($path ?: null);
            $receipt = $result instanceof Receipt ? $result : $result->receipt;
            $retainedPath = $receipt?->proof_path;

            return $result;
        } finally {
            if ($path && $retainedPath !== $path) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['amount' => $message]);
    }

    public function receive(User $actor, int $clientId, array $data): Receipt
    {
        $paymentDate = $data['payment_date'] ?? today()->toDateString();
        Validator::make(['payment_date' => $paymentDate], ['payment_date' => 'required|date_format:Y-m-d|before_or_equal:today'])->validate();
        $data['payment_date'] = $paymentDate;

        return DB::transaction(function () use ($actor, $clientId, $data) {
            $client = Client::lockForUpdate()->findOrFail($clientId);
            Gate::forUser($actor)->authorize('view', $client);
            DealershipContext::forWrite($actor, null, $client->dealership_id);
            $amount = Money::cents($data['amount']);
            if ($old = Receipt::where('token', $data['token'])->first()) {
                Gate::forUser($actor)->authorize('view', $old);
                if ($old->client_id != $clientId || Money::cents($old->amount) !== $amount
                    || (int) $old->source_order_id !== (int) ($data['source_order_id'] ?? 0)
                    || (int) $old->intended_installment_id !== (int) ($data['installment_id'] ?? 0)
                    || (int) $old->payment_method_id !== (int) $data['payment_method_id']
                    || $old->payment_date?->toDateString() !== $data['payment_date'] || $old->collection_channel !== ($data['collection_channel'] ?? 'head_office')) {
                    $this->fail('El comprobante ya fue utilizado con otros datos.');
                }

                return $old;
            }
            if (! $client->active || $amount <= 0 || $amount > 999999999999) {
                $this->fail('El cliente debe estar activo y el monto debe ser mayor a cero.');
            }
            $method = PaymentMethod::where('active', true)->where('dealership_id', $client->dealership_id)->lockForUpdate()->find($data['payment_method_id']);
            if (! $method) {
                $this->fail('El método de pago no está activo en este cliente.');
            }
            $intendedAmount = null;
            if (! empty($data['source_order_id'])) {
                $order = Order::lockForUpdate()->findOrFail($data['source_order_id']);
                Gate::forUser($actor)->authorize('update', $order);
                if ($order->client_id !== $client->id || $order->dealership_id !== $client->dealership_id || $order->cancelled) {
                    $this->fail('La venta debe estar activa y pertenecer al cliente del pago.');
                }
                if (! empty($data['reference']) && Receipt::where('source_order_id', $order->id)->where('client_id', $client->id)
                    ->where('payment_method_id', $method->id)->where('reference', trim($data['reference']))->where('amount', Money::decimal($amount))
                    ->whereDate('payment_date', $data['payment_date'])->whereNull('voided_at')->whereIn('approval_status', ['pending', 'approved'])->exists()) {
                    $this->fail('Este pago ya fue registrado para la venta con la misma referencia, fecha, método e importe. Revisa el comprobante existente.');
                }
                $limit = $order->balance_cents;
                if (! empty($data['installment_id'])) {
                    $target = $order->installments->firstWhere('id', (int) $data['installment_id']);
                    if (! $target) {
                        $this->fail('El compromiso no pertenece a la venta.');
                    }
                    $limit = $target->remaining_cents;
                }
                $reported = $order->reportedSummary();
                if ($reported['covered_cents'] > 0) {
                    $limit = min($limit, $order->unreportedBalanceCents(! empty($data['installment_id']) ? (int) $data['installment_id'] : null));
                    if ($limit <= 0 || $amount > $limit) {
                        $this->fail('Ya existen cobros pendientes para esta venta o cuota. El nuevo pago no puede superar el importe que falta por reportar: $'.Money::decimal($limit).'.');
                    }
                }
                $intendedAmount = Money::decimal(min($amount, $limit));
            } elseif (! empty($data['installment_id'])) {
                $this->fail('Selecciona una venta para relacionar un compromiso.');
            }
            $receipt = Receipt::create(['token' => $data['token'], 'client_id' => $client->id, 'user_id' => $client->user_id, 'dealership_id' => $client->dealership_id, 'recorded_by' => $actor->id, 'payment_method_id' => $method->id, 'amount' => Money::decimal($amount), 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'source_order_id' => $data['source_order_id'] ?? null]);
            if (! empty($data['proof_path'])) {
                $receipt->update(['proof_path' => $data['proof_path']]);
            }
            $channel = $data['collection_channel'] ?? 'head_office';
            Validator::make(['collection_channel' => $channel], ['collection_channel' => 'required|in:head_office,concessionaire'])->validate();
            $collector = null;
            if ($channel === 'concessionaire') {
                $collector = Client::whereKey($client->concessionaire_id)->where('customer_kind', 'dealership')->where('dealership_id', $client->dealership_id)->first();
                if (! $collector) {
                    $this->fail('El cliente debe tener un concesionario asignado para registrar un cobro recibido por el concesionario.');
                }
            }
            $receipt->update(['collection_channel' => $channel, 'collecting_concessionaire_id' => $collector?->id]);
            $pending = ! $actor->isAdmin() || $channel === 'concessionaire';
            $receipt->update(['payment_date' => $data['payment_date'], 'approval_status' => $pending ? 'pending' : 'approved', 'approved_at' => $pending ? null : now(), 'approved_by' => $pending ? null : $actor->id, 'intended_installment_id' => $data['installment_id'] ?? null, 'intended_amount' => $intendedAmount]);
            Audit::log($actor->id, 'receive', 'receipt', $receipt->id, ['amount' => $receipt->amount]);

            return $receipt;
        }, 3);
    }

    /** @return array{applied_cents: int, credit_cents: int, changed: bool, blocked: bool} */
    public function approvalPreview(Receipt $receipt): array
    {
        $amount = Money::cents($receipt->amount);
        $planned = Money::cents($receipt->intended_amount ?? $receipt->amount);
        $order = $receipt->sourceOrder;
        $blocked = $receipt->source_order_id && (! $order || $order->cancelled || $order->client_id !== $receipt->client_id || $order->dealership_id !== $receipt->dealership_id);
        $limit = $order && ! $blocked ? $order->balance_cents : 0;
        if ($order && $receipt->intended_installment_id) {
            $target = $order->installments->firstWhere('id', $receipt->intended_installment_id);
            $blocked = $blocked || ! $target;
            $limit = $target?->remaining_cents ?? 0;
        }
        $applied = $order && ! $blocked ? max(0, min($amount, $planned, $limit)) : 0;

        return ['applied_cents' => $applied, 'credit_cents' => $amount - $applied, 'changed' => (bool) $order && $applied !== min($amount, $planned), 'blocked' => (bool) $blocked];
    }

    public function approve(User $actor, Receipt $receipt, ?int $expectedAppliedCents = null, ?UploadedFile $approvalProof = null): Receipt
    {
        Gate::forUser($actor)->authorize('review', $receipt);
        Validator::make(['approval_proof' => $approvalProof], ['approval_proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120'])->validate();
        $path = $approvalProof?->store('receipt-proofs', 'local');
        $approved = null;
        try {
            $approved = $this->confirmApproval($actor, $receipt, $expectedAppliedCents, $path);

            return $approved;
        } finally {
            if ($path && $approved?->approval_proof_path !== $path) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function approveInRemittance(User $actor, Receipt $receipt, Remittance $remittance, ?int $expectedAppliedCents): Receipt
    {
        Gate::forUser($actor)->authorize('review', $remittance);
        $locked = Remittance::lockForUpdate()->findOrFail($remittance->id);
        abort_unless($locked->status === 'pending' && $locked->approval_proof_path && $receipt->collection_channel === 'concessionaire' && (int) $receipt->remittance_id === $locked->id && $receipt->dealership_id === $locked->dealership_id && (int) $receipt->collecting_concessionaire_id === $locked->concessionaire_id, 422);

        return $this->confirmApproval($actor, $receipt, $expectedAppliedCents, $locked->approval_proof_path, $locked->id);
    }

    private function confirmApproval(User $actor, Receipt $receipt, ?int $expectedAppliedCents, ?string $path, ?int $remittanceId = null): Receipt
    {
        return DB::transaction(function () use ($actor, $receipt, $expectedAppliedCents, $path, $remittanceId): Receipt {
            Client::lockForUpdate()->findOrFail($receipt->client_id);
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            Gate::forUser($actor)->authorize('review', $receipt);
            DealershipContext::forContactWrite($actor, null, $receipt->dealership_id);
            if ($receipt->collection_channel === 'concessionaire' && (! $remittanceId || (int) $receipt->remittance_id !== $remittanceId)) {
                $this->fail('Este cobro debe aprobarse mediante su remesa.');
            }
            if ($receipt->approval_status === 'approved' && ! $receipt->voided_at) {
                return $receipt;
            }
            if ($receipt->approval_status !== 'pending' || $receipt->voided_at) {
                $this->fail('Solo se puede aprobar un pago pendiente y sin anular.');
            }
            if (! $path) {
                throw ValidationException::withMessages(['approval_proof' => 'Adjunta el comprobante de ingreso en la cuenta de casa matriz para aprobar el pago.']);
            }
            if ($receipt->source_order_id) {
                $receipt->setRelation('sourceOrder', Order::lockForUpdate()->findOrFail($receipt->source_order_id));
            }
            $preview = $this->approvalPreview($receipt);
            if ($expectedAppliedCents !== null && $preview['applied_cents'] !== $expectedAppliedCents) {
                $this->fail('La deuda cambió desde que abriste el pago. Actualiza la ficha y revisa los importes antes de aprobar.');
            }
            $receipt->update(['approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id, 'approval_proof_path' => $path]);
            if ($receipt->source_order_id) {
                $order = Order::lockForUpdate()->findOrFail($receipt->source_order_id);
                if ($order->cancelled || $order->client_id !== $receipt->client_id || $order->dealership_id !== $receipt->dealership_id) {
                    $this->fail('La venta relacionada fue cancelada o cambió. Rechaza el pago y solicita un nuevo registro.');
                }
                $limit = $order->balance_cents;
                if ($receipt->intended_installment_id) {
                    $target = $order->installments->firstWhere('id', $receipt->intended_installment_id);
                    if (! $target) {
                        $this->fail('El compromiso relacionado ya no pertenece a la venta.');
                    }
                    $limit = $target->remaining_cents;
                }
                $amount = min($limit, Money::cents($receipt->intended_amount ?? $receipt->amount));
                if ($amount > 0) {
                    $this->apply($actor, $receipt, $order->id, ['token' => $receipt->token, 'amount' => Money::decimal($amount), 'installment_id' => $receipt->intended_installment_id]);
                }
            }
            Audit::log($actor->id, 'approve_receipt', 'receipt', $receipt->id, ['amount' => $receipt->amount, 'source_order_id' => $receipt->source_order_id]);

            return $receipt->fresh();
        }, 3);
    }

    public function reject(User $actor, Receipt $receipt, string $reason): void
    {
        DB::transaction(function () use ($actor, $receipt, $reason): void {
            Client::lockForUpdate()->findOrFail($receipt->client_id);
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            Gate::forUser($actor)->authorize('review', $receipt);
            DealershipContext::forContactWrite($actor, null, $receipt->dealership_id);
            if ($receipt->collection_channel === 'concessionaire') {
                $this->fail('Rechaza la remesa para devolver los cobros al concesionario sin perder su registro.');
            }
            if ($receipt->approval_status !== 'pending' || $receipt->voided_at) {
                $this->fail('Solo se puede rechazar un pago pendiente y sin anular.');
            }
            $receipt->update(['approval_status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);
            Audit::log($actor->id, 'reject_receipt', 'receipt', $receipt->id, ['reason' => $reason]);
        }, 3);
    }

    public function apply(User $actor, Receipt $receipt, int $orderId, array $data): Payment
    {
        return DB::transaction(function () use ($actor, $receipt, $orderId, $data) {
            $client = Client::lockForUpdate()->findOrFail($receipt->client_id);
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            Gate::forUser($actor)->authorize($actor->isHeadOffice() ? 'review' : 'apply', $receipt);
            DealershipContext::forContactWrite($actor, null, $receipt->dealership_id);
            $order = Order::lockForUpdate()->findOrFail($orderId);
            if ($actor->isHeadOffice()) {
                abort_unless($receipt->source_order_id === $order->id && $receipt->approved_by === $actor->id, 403);
            } else {
                Gate::forUser($actor)->authorize('update', $order);
            }
            if ($order->client_id != $receipt->client_id || $order->dealership_id != $receipt->dealership_id) {
                $this->fail('La venta debe pertenecer al mismo cliente y cliente del pago.');
            }
            $amount = Money::cents($data['amount']);
            if ($receipt->approval_status !== 'approved') {
                $this->fail('El pago debe ser aprobado por casa matriz antes de utilizarlo.');
            }
            if ($old = Payment::where('token', $data['token'])->first()) {
                if ($old->receipt_id != $receipt->id || $old->order_id != $orderId || Money::cents($old->amount) !== $amount) {
                    $this->fail('La aplicación ya fue registrada con otros datos.');
                }

                return $old;
            }
            if ($receipt->voided_at || $order->cancelled || $amount <= 0 || $amount > $receipt->available_cents || $amount > $order->balance_cents) {
                $this->fail('El monto debe ser mayor a cero y no superar el saldo disponible del pago ni la deuda de la venta.');
            }
            $schedule = $order->installments->values();
            if (! empty($data['installment_id'])) {
                $target = $schedule->firstWhere('id', (int) $data['installment_id']);
                if (! $target || $amount > $target->remaining_cents) {
                    $this->fail('La cuota no pertenece a la venta o el monto supera su saldo.');
                }
                $schedule = collect([$target]);
            }
            $initialPaid = $order->payments->whereNull('voided_at')->where('is_initial', true)->sum(fn ($p) => Money::cents($p->amount));
            $unallocated = $order->payments->whereNull('voided_at')->where('is_initial', false)->sum(fn ($p) => Money::cents($p->amount) - $p->allocations->sum(fn ($a) => Money::cents($a->amount)));
            $p = $order->payments()->create(['receipt_id' => $receipt->id, 'token' => $data['token'], 'user_id' => $order->user_id, 'dealership_id' => $order->dealership_id, 'recorded_by' => $actor->id, 'payment_method_id' => $receipt->payment_method_id, 'amount' => Money::decimal($amount), 'reference' => $receipt->reference, 'notes' => $data['notes'] ?? $receipt->notes]);
            $remaining = $amount;
            if (empty($data['installment_id']) && ! $order->installments->contains(fn ($item): bool => $item->number === 0)) {
                $remaining -= min($remaining, max(0, Money::cents($order->initial_amount) - $initialPaid - $unallocated));
            }
            foreach ($schedule as $i) {
                $part = min($remaining, $i->remaining_cents);
                if ($part > 0) {
                    $p->allocations()->create(['installment_schedule_id' => $i->id, 'amount' => Money::decimal($part)]);
                    $remaining -= $part;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            if ($remaining > 0) {
                $this->fail('No fue posible distribuir el abono.');
            }
            Audit::log($actor->id, 'payment', 'payment', $p->id, ['amount' => $p->amount, 'receipt_id' => $receipt->id]);

            return $p;
        }, 3);
    }

    public function void(User $actor, Receipt $receipt, string $reason, ?int $remittanceId = null): void
    {
        abort_unless($actor->canAccessRecord($receipt) && ($actor->isAdmin() || $actor->isHeadOffice() || ($actor->canManageBusiness() && $receipt->approval_status === 'pending')), 403);
        DB::transaction(function () use ($actor, $receipt, $reason, $remittanceId) {
            Client::lockForUpdate()->findOrFail($receipt->client_id);
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            abort_unless($actor->canAccessRecord($receipt) && ($actor->isAdmin() || $actor->isHeadOffice() || ($actor->canManageBusiness() && $receipt->approval_status === 'pending')), 403);
            DealershipContext::forContactWrite($actor, null, $receipt->dealership_id);
            if ($receipt->remittance_id && (int) $receipt->remittance_id !== $remittanceId) {
                $this->fail('Este cobro pertenece a una remesa. Su anulación debe gestionarse desde la remesa.');
            }
            if ($receipt->voided_at) {
                return;
            }
            $applications = $receipt->applications()->whereNull('voided_at')->orderBy('order_id')->get();
            Order::whereIn('id', $applications->pluck('order_id'))->orderBy('id')->lockForUpdate()->get();
            $void = ['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason];
            $receipt->applications()->whereNull('voided_at')->update($void);
            $receipt->update($void);
            Audit::log($actor->id, 'void_receipt', 'receipt', $receipt->id, ['reason' => $reason]);
        }, 3);
    }
}
