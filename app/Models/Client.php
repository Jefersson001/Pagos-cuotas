<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends OwnedModel
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (Client $client): void {
            if ($client->customer_kind === 'dealership') {
                $client->started_at = ($client->created_at ?? $client->freshTimestamp())->toDateString();
            }
        });
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'affiliation_date' => 'date', 'concessionaire_id' => 'integer', 'list_number' => 'integer', 'refrigerators' => 'integer', 'refrigerators_assigned_at' => 'date', 'listed_started_at' => 'date', 'registry_date' => 'date', 'started_at' => 'date'];
    }

    public function scopeHeadOfficeCustomers(Builder $query, User $actor): Builder
    {
        abort_unless($actor->isHeadOffice() && $actor->hasActiveAccess(), 403);

        return $query->where('dealership_id', $actor->dealership_id)->where('customer_kind', 'person')
            ->whereHas('concessionaire', fn (Builder $dealers) => $dealers
                ->where('customer_kind', 'dealership')->where('dealership_id', $actor->dealership_id));
    }

    public function scopeAffiliatedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from || $to) {
            $query->where('customer_kind', 'person');
        }
        if ($from) {
            $query->whereDate('affiliation_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('affiliation_date', '<=', $to);
        }

        return $query;
    }

    public function scopeStartedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from || $to) {
            $query->where('customer_kind', 'dealership');
        }
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
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
