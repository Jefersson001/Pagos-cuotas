<?php

namespace App\Models;

class Payment extends OwnedModel
{
    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_initial' => 'boolean', 'voided_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
