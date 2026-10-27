<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends OwnedModel
{
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getAppliedCentsAttribute(): int
    {
        return $this->applications->whereNull('voided_at')->sum(fn ($p) => Money::cents($p->amount));
    }

    public function getAvailableCentsAttribute(): int
    {
        return $this->voided_at ? 0 : Money::cents($this->amount) - $this->applied_cents;
    }

    public function getStatusAttribute(): string
    {
        return $this->voided_at ? 'Anulado' : ($this->available_cents === 0 ? 'Aplicado' : ($this->applied_cents > 0 ? 'Parcialmente aplicado' : 'Sin aplicar'));
    }
}
