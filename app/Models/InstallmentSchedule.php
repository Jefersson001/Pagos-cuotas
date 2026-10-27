<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class InstallmentSchedule extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2', 'number' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function getRemainingCentsAttribute(): int
    {
        return Money::cents($this->amount) - $this->allocations->filter(fn ($a) => ! $a->payment->voided_at)->sum(fn ($a) => Money::cents($a->amount));
    }

    public function getLabelAttribute(): string
    {
        return $this->number === 0 ? 'Inicial' : 'Cuota '.$this->number;
    }
}
