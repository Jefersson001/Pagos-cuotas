<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Remittance;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RemittanceService
{
    /** @return Collection<int,array{applied_cents:int,credit_cents:int,changed:bool,blocked:bool}> */
    public function preview(Remittance $remittance): Collection
    {
        $states = [];

        return $remittance->receipts->sortBy('id')->mapWithKeys(function (Receipt $receipt) use (&$states): array {
            $preview = app(ReceiptService::class)->approvalPreview($receipt);
            $order = $receipt->sourceOrder;
            if (! $order || $preview['blocked']) {
                return [$receipt->id => $preview];
            }
            if (! isset($states[$order->id])) {
                $left = $order->installments->mapWithKeys(fn ($item): array => [$item->id => max(0, $item->remaining_cents)])->all();
                $states[$order->id] = ['balance' => max(0, $order->balance_cents), 'items' => $left, 'legacy' => max(0, $order->balance_cents - array_sum($left))];
            }
            $state = &$states[$order->id];
            $applied = min($preview['applied_cents'], $state['balance']);
            if ($receipt->intended_installment_id) {
                $id = $receipt->intended_installment_id;
                $applied = min($applied, $state['items'][$id] ?? 0);
                $state['items'][$id] = ($state['items'][$id] ?? 0) - $applied;
            } else {
                $budget = $applied;
                $part = min($budget, $state['legacy']);
                $budget -= $part;
                $state['legacy'] -= $part;
                foreach ($state['items'] as $id => $remaining) {
                    $part = min($budget, $remaining);
                    $state['items'][$id] -= $part;
                    $budget -= $part;
                    if ($budget === 0) {
                        break;
                    }
                }
            }
            $state['balance'] -= $applied;

            return [$receipt->id => ['applied_cents' => $applied, 'credit_cents' => Money::cents($receipt->amount) - $applied, 'changed' => $preview['changed'] || $applied !== $preview['applied_cents'], 'blocked' => $preview['blocked']]];
        });
    }

    /** @param array<string,mixed> $values */
    public function create(User $actor, array $values, UploadedFile $proof): Remittance
    {
        Gate::forUser($actor)->authorize('create', Remittance::class);
        Validator::make($values, ['token' => 'required|uuid', 'concessionaire_id' => 'required|integer', 'payment_method_id' => 'required|integer', 'receipt_ids' => 'required|array|min:1|max:100', 'receipt_ids.*' => 'required|integer|distinct', 'amount' => 'required|string', 'reference' => 'required|string|max:255', 'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today'])->validate();
        Validator::make(['proof' => $proof], ['proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120'])->validate();
        $path = $proof->store('remittance-proofs', 'local');
        if (! $path) {
            throw ValidationException::withMessages(['proof' => 'No se pudo guardar el comprobante.']);
        }
        $kept = null;
        try {
            $result = DB::transaction(function () use ($actor, $values, $path): Remittance {
                $branch = Client::visibleTo($actor)->whereKey($values['concessionaire_id'])->where('customer_kind', 'dealership')->first();
                if (! $actor->isAdmin()) {
                    $branch = Client::whereKey($actor->concessionaire_id)->whereKey($values['concessionaire_id'])->where('customer_kind', 'dealership')->where('dealership_id', $actor->dealership_id)->first();
                }
                abort_unless($branch, 403);
                DealershipContext::forWrite($actor, null, $branch->dealership_id);
                $ids = array_map('intval', $values['receipt_ids']);
                sort($ids);
                $found = Receipt::visibleTo($actor)->whereIn('id', $ids)->get(['id', 'client_id']);
                if ($found->count() !== count($ids)) {
                    throw ValidationException::withMessages(['receipt_ids' => 'Hay cobros que no pertenecen a tu cartera.']);
                }
                Client::whereIn('id', $found->pluck('client_id')->unique())->orderBy('id')->lockForUpdate()->get();
                $receipts = Receipt::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($old = Remittance::where('token', $values['token'])->first()) {
                    Gate::forUser($actor)->authorize('view', $old);
                    $previous = $old->receipts()->pluck('receipts.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                    if ($previous !== $ids || $old->concessionaire_id !== $branch->id || Money::cents($old->amount) !== Money::cents($values['amount']) || $old->reference !== $values['reference'] || (int) $old->payment_method_id !== (int) $values['payment_method_id'] || $old->payment_date->toDateString() !== $values['payment_date']) {
                        throw ValidationException::withMessages(['receipt_ids' => 'Este envío ya fue registrado con otros datos.']);
                    }

                    return $old;
                }
                $method = PaymentMethod::where('dealership_id', $branch->dealership_id)->where('active', true)->lockForUpdate()->find($values['payment_method_id']);
                if (! $method) {
                    throw ValidationException::withMessages(['payment_method_id' => 'Selecciona un método activo de la empresa principal.']);
                }
                if (Remittance::where('dealership_id', $branch->dealership_id)->where('payment_method_id', $method->id)->where('reference', $values['reference'])->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['reference' => 'Esta referencia ya corresponde a otra remesa pendiente o aprobada del mismo método.']);
                }
                foreach ($receipts as $receipt) {
                    if ($receipt->collection_channel !== 'concessionaire' || $receipt->approval_status !== 'pending' || $receipt->voided_at || $receipt->remittance_id || $receipt->dealership_id !== $branch->dealership_id || (int) $receipt->collecting_concessionaire_id !== $branch->id) {
                        throw ValidationException::withMessages(['receipt_ids' => 'Selecciona cobros del mismo concesionario, pendientes de envío y sin anular.']);
                    }
                }
                $sum = (int) $receipts->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount));
                if ($sum <= 0 || $sum > 999999999999 || Money::cents($values['amount']) !== $sum) {
                    throw ValidationException::withMessages(['amount' => 'El importe transferido debe coincidir exactamente con la suma de los cobros seleccionados.']);
                }
                $remittance = Remittance::create(['token' => $values['token'], 'dealership_id' => $branch->dealership_id, 'concessionaire_id' => $branch->id, 'recorded_by' => $actor->id, 'payment_method_id' => $method->id, 'payment_date' => $values['payment_date'], 'reference' => $values['reference'], 'amount' => Money::decimal($sum), 'proof_path' => $path, 'status' => 'pending']);
                $remittance->receipts()->attach($ids);
                Receipt::whereIn('id', $ids)->update(['remittance_id' => $remittance->id]);
                Audit::log($actor->id, 'submit_remittance', 'remittance', $remittance->id, ['receipt_ids' => $ids, 'amount' => $remittance->amount]);

                return $remittance;
            }, 3);
            $kept = $result->proof_path;

            return $result;
        } finally {
            if ($kept !== $path) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /** @param array<int|string,int|string> $expected */
    public function approve(User $actor, Remittance $remittance, UploadedFile $proof, array $expected): Remittance
    {
        Gate::forUser($actor)->authorize('review', $remittance);
        Validator::make(['approval_proof' => $proof], ['approval_proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120'])->validate();
        $path = $proof->store('remittance-proofs', 'local');
        if (! $path) {
            throw ValidationException::withMessages(['approval_proof' => 'No se pudo guardar el comprobante de casa matriz.']);
        }
        $kept = null;
        try {
            $result = DB::transaction(function () use ($actor, $remittance, $path, $expected): Remittance {
                $this->lockClients($remittance);
                $locked = Remittance::lockForUpdate()->findOrFail($remittance->id);
                Gate::forUser($actor)->authorize('review', $locked);
                DealershipContext::forContactWrite($actor, null, $locked->dealership_id);
                if ($locked->status === 'approved') {
                    return $locked;
                }
                if ($locked->status !== 'pending') {
                    throw ValidationException::withMessages(['remittance' => 'Solo se puede aprobar una remesa pendiente.']);
                }
                $receipts = Receipt::where('remittance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
                $ids = $locked->receipts()->pluck('receipts.id')->sort()->values()->all();
                $expectedIds = array_map('intval', array_keys($expected));
                sort($expectedIds);
                if ($receipts->modelKeys() !== $ids || $expectedIds !== $ids || $receipts->sum(fn (Receipt $r): int => Money::cents($r->amount)) !== Money::cents($locked->amount)) {
                    throw ValidationException::withMessages(['remittance' => 'Los cobros o el importe cambiaron. Actualiza la remesa antes de aprobar.']);
                }
                $locked->update(['approval_proof_path' => $path]);
                foreach ($receipts as $receipt) {
                    if ($receipt->approval_status !== 'pending' || $receipt->voided_at) {
                        throw ValidationException::withMessages(['remittance' => 'La remesa contiene un cobro que ya no está pendiente.']);
                    }
                    app(ReceiptService::class)->approveInRemittance($actor, $receipt, $locked, (int) $expected[$receipt->id]);
                }
                $locked->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id]);
                Audit::log($actor->id, 'approve_remittance', 'remittance', $locked->id, ['amount' => $locked->amount, 'receipt_ids' => $ids]);

                return $locked;
            }, 3);
            $kept = $result->approval_proof_path;

            return $result;
        } finally {
            if ($kept !== $path) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function reject(User $actor, Remittance $remittance, string $reason): void
    {
        Gate::forUser($actor)->authorize('review', $remittance);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:10|max:1000'])->validate();
        DB::transaction(function () use ($actor, $remittance, $reason): void {
            $this->lockClients($remittance);
            $locked = Remittance::lockForUpdate()->findOrFail($remittance->id);
            Gate::forUser($actor)->authorize('review', $locked);
            DealershipContext::forContactWrite($actor, null, $locked->dealership_id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['remittance' => 'Solo se puede rechazar una remesa pendiente.']);
            }
            Receipt::where('remittance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            Receipt::where('remittance_id', $locked->id)->update(['remittance_id' => null]);
            $locked->update(['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);
            Audit::log($actor->id, 'reject_remittance', 'remittance', $locked->id, ['reason' => $reason, 'receipts_preserved' => true]);
        }, 3);
    }

    public function void(User $actor, Remittance $remittance, string $reason): void
    {
        Gate::forUser($actor)->authorize('review', $remittance);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:10|max:1000'])->validate();
        DB::transaction(function () use ($actor, $remittance, $reason): void {
            $this->lockClients($remittance);
            $locked = Remittance::lockForUpdate()->findOrFail($remittance->id);
            Gate::forUser($actor)->authorize('review', $locked);
            if ($locked->status === 'void') {
                return;
            }
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['remittance' => 'Solo se puede anular una remesa aprobada. Para corregir un envío pendiente, recházalo.']);
            }
            $receipts = Receipt::where('remittance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($receipts as $receipt) {
                app(ReceiptService::class)->void($actor, $receipt, $reason, $locked->id);
            }
            $locked->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            Audit::log($actor->id, 'void_remittance', 'remittance', $locked->id, ['reason' => $reason]);
        }, 3);
    }

    private function lockClients(Remittance $remittance): void
    {
        $ids = $remittance->receipts()->pluck('receipts.client_id')->unique()->sort()->values();
        Client::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }
}
