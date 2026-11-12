<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

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
