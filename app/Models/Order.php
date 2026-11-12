<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class Order extends OwnedModel
{
    public function scopeHeadOfficePortfolio(Builder $query, User $actor): Builder
    {
        abort_unless($actor->isHeadOffice() && $actor->hasActiveAccess(), 403);

        return $query->where('dealership_id', $actor->dealership_id)
            ->whereHas('client', fn (Builder $clients) => $clients->headOfficeCustomers($actor));
    }

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'initial_amount' => 'decimal:2', 'cancelled' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function installments()
    {
        return $this->hasMany(InstallmentSchedule::class)
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM order_items WHERE order_items.id = installment_schedules.order_item_id AND order_items.is_freight = 1) THEN 3 WHEN order_item_id IS NOT NULL THEN 0 WHEN number = 0 THEN 1 ELSE 2 END')
            ->orderBy('due_date')->orderBy('number')->orderBy('id');
    }

    public function canAddFreight(): bool
    {
        if ($this->cancelled || $this->items->contains('is_freight', true)) {
            return false;
        }
        if (! $this->items()->where('is_freight', false)->whereHas('product', fn (Builder $products): Builder => $products
            ->where('uses_freight', true)->whereIn('product_type', ['general', 'vehicle'])->where('dealership_id', $this->dealership_id))->exists()) {
            return false;
        }
        $regular = $this->installments->filter(fn (InstallmentSchedule $installment): bool => $installment->order_item_id === null && $installment->number > 0)->sortBy('number')->values();

        return $regular->count() >= 2 && $regular[$regular->count() - 2]->remaining_cents === 0;
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** @return array{reported_cents: int, covered_cents: int, remaining_cents: int, installments: array<int,int>} */
    public function reportedSummary(?Collection $pendingReceipts = null): array
    {
        $pending = $pendingReceipts ?? Receipt::where('source_order_id', $this->id)->where('client_id', $this->client_id)->where('dealership_id', $this->dealership_id)
            ->where('approval_status', 'pending')->whereNull('voided_at')->where('created_at', '<=', now())->orderBy('id')->get();
        $pending = $pending->filter(fn (Receipt $receipt): bool => $receipt->source_order_id === $this->id && $receipt->client_id === $this->client_id && $receipt->dealership_id === $this->dealership_id && $receipt->approval_status === 'pending' && ! $receipt->voided_at)->sortBy('id');
        $available = $this->installments->mapWithKeys(fn (InstallmentSchedule $item): array => [$item->id => max(0, $item->remaining_cents)])->all();
        $covered = array_fill_keys(array_keys($available), 0);
        $balance = max(0, $this->balance_cents);
        $legacy = max(0, $balance - array_sum($available));
        $total = 0;
        foreach ($pending as $receipt) {
            $budget = min(Money::cents($receipt->amount), Money::cents($receipt->intended_amount ?? $receipt->amount), $balance - $total);
            if ($receipt->intended_installment_id) {
                $id = $receipt->intended_installment_id;
                $part = min($budget, $available[$id] ?? 0);
                $available[$id] = ($available[$id] ?? 0) - $part;
                $covered[$id] = ($covered[$id] ?? 0) + $part;
                $total += $part;

                continue;
            }
            $part = min($budget, $legacy);
            $legacy -= $part;
            $budget -= $part;
            $total += $part;
            foreach ($available as $id => $remaining) {
                $part = min($budget, $remaining);
                $available[$id] -= $part;
                $covered[$id] += $part;
                $total += $part;
                $budget -= $part;
                if ($budget === 0) {
                    break;
                }
            }
        }

        return ['reported_cents' => (int) $pending->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount)), 'covered_cents' => $this->cancelled ? 0 : $total,
            'remaining_cents' => $this->cancelled ? 0 : max(0, $balance - $total), 'installments' => $this->cancelled ? [] : $covered];
    }

    public function unreportedBalanceCents(?int $installmentId = null, ?Collection $pendingReceipts = null): int
    {
        $reported = $this->reportedSummary($pendingReceipts);
        if ($installmentId === null) {
            return $reported['remaining_cents'];
        }
        $installment = $this->installments->firstWhere('id', $installmentId);

        return min($reported['remaining_cents'], max(0, ($installment?->remaining_cents ?? 0) - ($reported['installments'][$installmentId] ?? 0)));
    }

    public function getPaidCentsAttribute(): int
    {
        return $this->payments->whereNull('voided_at')->sum(fn ($p) => Money::cents($p->amount));
    }

    public function getBalanceCentsAttribute(): int
    {
        return Money::cents($this->total) - $this->paid_cents;
    }

    public function getStatusAttribute(): string
    {
        if ($this->cancelled) {
            return 'Cancelado';
        }if ($this->balance_cents === 0) {
            return 'Pagado';
        }
        if ($this->installments->contains(fn ($i) => $i->remaining_cents > 0 && $i->due_date->lt(today()))) {
            return 'Atrasado';
        }

        return $this->paid_cents > 0 ? 'En proceso de pago' : 'Pendiente de pago';
    }
}
