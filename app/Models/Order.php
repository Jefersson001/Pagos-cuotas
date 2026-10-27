<?php

namespace App\Models;

use App\Support\Money;

class Order extends OwnedModel
{
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
        return $this->hasMany(InstallmentSchedule::class)->orderBy('number');
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
