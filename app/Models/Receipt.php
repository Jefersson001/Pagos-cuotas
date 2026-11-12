<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends OwnedModel
{
    use HasFactory;

    protected $attributes = ['approval_status' => 'approved'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:2', 'intended_amount' => 'decimal:2', 'voided_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }

    public function remittances(): BelongsToMany
    {
        return $this->belongsToMany(Remittance::class, 'remittance_receipt');
    }

    public function getCollectionLabelAttribute(): string
    {
        if ($this->collection_channel !== 'concessionaire') {
            return 'Pago directo a casa matriz';
        }

        return $this->remittance_id ? 'Incluido en remesa' : 'Recibido por concesionario · Pendiente de envío';
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function intendedInstallment(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'intended_installment_id');
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
        return $this->voided_at || $this->approval_status !== 'approved' ? 0 : Money::cents($this->amount) - $this->applied_cents;
    }

    public function getStatusAttribute(): string
    {
        if (! $this->voided_at && $this->approval_status !== 'approved') {
            return $this->approval_status === 'rejected' ? 'Rechazado' : $this->approval_label;
        }

        return $this->voided_at ? 'Anulado' : ($this->available_cents === 0 ? 'Aplicado' : ($this->applied_cents > 0 ? 'Parcialmente aplicado' : 'Sin aplicar'));
    }

    public function getApprovalLabelAttribute(): string
    {
        if (! $this->voided_at && $this->approval_status === 'pending' && $this->collection_channel === 'concessionaire') {
            return $this->remittance_id ? 'Pendiente de aprobación de remesa' : 'Pendiente de envío';
        }

        return $this->voided_at ? 'Anulado' : match ($this->approval_status) {
            'approved' => 'Aprobado', 'rejected' => 'Rechazado', default => 'Pendiente de aprobación',
        };
    }

    public function getApplicationStatusAttribute(): string
    {
        if ($this->voided_at || $this->approval_status !== 'approved') {
            return 'Sin aplicación';
        }

        return $this->applied_cents === 0 ? 'Sin aplicar' : ($this->available_cents > 0 ? 'Parcialmente aplicado' : 'Aplicado');
    }
}
