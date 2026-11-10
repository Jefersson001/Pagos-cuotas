<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentSchedule extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $with = ['orderItem'];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2', 'number' => 'integer', 'order_item_id' => 'integer'];
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
        if ($this->order_item_id !== null) {
            return 'Pago completo: '.$this->orderItem->description;
        }

        return $this->number === 0 ? 'Inicial' : 'Cuota '.$this->number;
    }
}
