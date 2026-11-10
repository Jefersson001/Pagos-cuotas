<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends OwnedModel
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'concessionaire_id' => 'integer', 'list_number' => 'integer', 'refrigerators' => 'integer', 'refrigerators_assigned_at' => 'date', 'listed_started_at' => 'date', 'registry_date' => 'date', 'started_at' => 'date'];
    }

    public function concessionaire(): BelongsTo
    {
        return $this->belongsTo(self::class, 'concessionaire_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(self::class, 'concessionaire_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function getCreditCentsAttribute(): int
    {
        return $this->receipts->sum('available_cents');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
