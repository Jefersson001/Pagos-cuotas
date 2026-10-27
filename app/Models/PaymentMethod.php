<?php

namespace App\Models;

use App\Services\DealershipContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (PaymentMethod $method): void {
            if (! $method->dealership_id) {
                $method->dealership_id = auth()->check() ? DealershipContext::forWrite(auth()->user()) : Dealership::orderBy('id')->value('id');
            }
        });
    }

    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $id = DealershipContext::id($user);

        return $id ? $query->where('dealership_id', $id) : ($user->isAdmin() ? $query : $query->whereRaw('1=0'));
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'dealership_id' => 'integer'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
