<?php

namespace App\Models;

use App\Services\DealershipContext;
use Database\Factories\RemittanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Remittance extends Model
{
    /** @use HasFactory<RemittanceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'voided_at' => 'datetime', 'dealership_id' => 'integer', 'concessionaire_id' => 'integer'];
    }

    public function receipts(): BelongsToMany
    {
        return $this->belongsToMany(Receipt::class, 'remittance_receipt');
    }

    public function concessionaire(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'concessionaire_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->isCustomer() || ! $actor->hasActiveAccess()) {
            return $query->whereRaw('1=0');
        }
        if ($actor->isAdmin()) {
            return $query->when(DealershipContext::id($actor), fn (Builder $q, int $id): Builder => $q->where('dealership_id', $id));
        }
        $query->where('dealership_id', $actor->dealership_id);
        if (! $actor->isHeadOffice()) {
            $query->where('concessionaire_id', $actor->concessionaire_id ?? 0);
        }

        return $query;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'approved' => 'Aprobada','rejected' => 'Rechazada','void' => 'Anulada',default => 'Pendiente de aprobación'
        };
    }
}
