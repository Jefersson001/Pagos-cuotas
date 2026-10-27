<?php

namespace App\Models;

use App\Services\DealershipContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class OwnedModel extends Model
{
    protected $guarded = [];

    protected $casts = ['dealership_id' => 'integer', 'user_id' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (OwnedModel $record): void {
            if (! $record->dealership_id) {
                $record->dealership_id = $record->order_id ? Order::findOrFail($record->order_id)->dealership_id : (User::find($record->user_id)?->dealership_id ?? DealershipContext::id(auth()->user() ?? User::findOrFail($record->user_id)) ?? Dealership::orderBy('id')->value('id'));
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
        if ($id) {
            $query->where($this->getTable().'.dealership_id', $id);
        }
        if (! $user->isAdmin() && ! $id) {
            $query->whereRaw('1=0');
        }

        return $user->canManageUsers() ? $query : $query->where($this->getTable().'.user_id', $user->id);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
