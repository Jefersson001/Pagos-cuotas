<?php

namespace App\Models;

use App\Services\DealershipContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends OwnedModel
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'initial_amount' => 'decimal:2', 'active' => 'boolean'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $id = DealershipContext::id($user);

        return $id ? $query->where('dealership_id', $id) : ($user->isAdmin() ? $query : $query->whereRaw('1=0'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
