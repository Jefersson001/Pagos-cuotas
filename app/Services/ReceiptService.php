<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['amount' => $message]);
    }

    public function receive(User $actor, int $clientId, array $data): Receipt
    {
        return DB::transaction(function () use ($actor, $clientId, $data) {
            $client = Client::lockForUpdate()->findOrFail($clientId);
            Gate::forUser($actor)->authorize('view', $client);
            DealershipContext::forWrite($actor, null, $client->dealership_id);
            $amount = Money::cents($data['amount']);
            if ($old = Receipt::where('token', $data['token'])->first()) {
                Gate::forUser($actor)->authorize('view', $old);
                if ($old->client_id != $clientId || Money::cents($old->amount) !== $amount) {
                    $this->fail('El comprobante ya fue utilizado con otros datos.');
                }

                return $old;
            }
            if (! $client->active || $amount <= 0 || $amount > 999999999999) {
                $this->fail('El concesionario debe estar activo y el monto debe ser mayor a cero.');
            }
            $method = PaymentMethod::where('active', true)->where('dealership_id', $client->dealership_id)->lockForUpdate()->find($data['payment_method_id']);
            if (! $method) {
                $this->fail('El método de pago no está activo en este concesionario.');
            }
            $receipt = Receipt::create(['token' => $data['token'], 'client_id' => $client->id, 'user_id' => $client->user_id, 'dealership_id' => $client->dealership_id, 'recorded_by' => $actor->id, 'payment_method_id' => $method->id, 'amount' => Money::decimal($amount), 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'source_order_id' => $data['source_order_id'] ?? null]);
            Audit::log($actor->id, 'receive', 'receipt', $receipt->id, ['amount' => $receipt->amount]);

            return $receipt;
        }, 3);
    }

    public function apply(User $actor, Receipt $receipt, int $orderId, array $data): Payment
    {
        return DB::transaction(function () use ($actor, $receipt, $orderId, $data) {
            $client = Client::lockForUpdate()->findOrFail($receipt->client_id);
            Gate::forUser($actor)->authorize('view', $client);
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            Gate::forUser($actor)->authorize('update', $receipt);
            DealershipContext::forWrite($actor, null, $receipt->dealership_id);
            $order = Order::lockForUpdate()->findOrFail($orderId);
            Gate::forUser($actor)->authorize('update', $order);
            if ($order->client_id != $receipt->client_id || $order->dealership_id != $receipt->dealership_id) {
                $this->fail('La venta debe pertenecer al mismo concesionario y concesionario del pago.');
            }
            $amount = Money::cents($data['amount']);
            if ($old = Payment::where('token', $data['token'])->first()) {
                if ($old->receipt_id != $receipt->id || $old->order_id != $orderId || Money::cents($old->amount) !== $amount) {
                    $this->fail('La aplicación ya fue registrada con otros datos.');
                }

                return $old;
            }
            if ($receipt->voided_at || $order->cancelled || $amount <= 0 || $amount > $receipt->available_cents || $amount > $order->balance_cents) {
                $this->fail('El monto debe ser mayor a cero y no superar el saldo disponible del pago ni la deuda de la venta.');
            }
            $schedule = $order->installments->sortBy(fn ($i) => ($i->number === 0 ? '0' : '1').$i->due_date->toDateString())->values();
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
            if (empty($data['installment_id']) && ! $order->installments->contains('number', 0)) {
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

    public function void(User $actor, Receipt $receipt, string $reason): void
    {
        abort_unless($actor->canManageUsers() && $actor->canAccessRecord($receipt), 403);
        DB::transaction(function () use ($actor, $receipt, $reason) {
            Client::lockForUpdate()->findOrFail($receipt->client_id);
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            Gate::forUser($actor)->authorize('update', $receipt);
            DealershipContext::forWrite($actor, null, $receipt->dealership_id);
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
