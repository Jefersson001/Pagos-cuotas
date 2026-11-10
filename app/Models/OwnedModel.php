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
        if ($user->isHeadOffice()) {
            if ($this instanceof Receipt) {
                return $query->where('dealership_id', $user->dealership_id)->whereHas('client', fn (Builder $clients): Builder => $clients
                    ->where('dealership_id', $user->dealership_id)->where('customer_kind', 'person')
                    ->whereHas('concessionaire', fn (Builder $dealers): Builder => $dealers->where('dealership_id', $user->dealership_id)->where('customer_kind', 'dealership')));
            }

            return $this instanceof Client
                ? $query->where('dealership_id', $user->dealership_id)->where('customer_kind', 'dealership')
                : $query->whereRaw('1=0');
        }
        if ($user->isCustomer()) {
            $query->where($this->getTable().'.dealership_id', $user->dealership_id);

            return match (true) {
                $this instanceof Client => $query->whereKey($user->client_id ?? 0),
                $this instanceof Order, $this instanceof Receipt => $query->where('client_id', $user->client_id ?? 0),
                $this instanceof Payment => $query->whereHas('order', fn ($order) => $order->where('client_id', $user->client_id ?? 0)),
                default => $query->whereRaw('1=0'),
            };
        }
        $id = DealershipContext::id($user);
        if ($id) {
            $query->where($this->getTable().'.dealership_id', $id);
        }
        if (! $user->isAdmin() && ! $id) {
            $query->whereRaw('1=0');
        }

        if (! $user->isAdmin() && $user->concessionaire_id) {
            $clientScope = fn (Builder $clients): Builder => $clients->where('dealership_id', $user->dealership_id)->where('customer_kind', 'person')->where('concessionaire_id', $user->concessionaire_id);
            match (true) {
                $this instanceof Client => $clientScope($query),
                $this instanceof Order, $this instanceof Receipt => $query->whereHas('client', $clientScope),
                $this instanceof Payment => $query->whereHas('order', fn (Builder $orders) => $orders->visibleTo($user)),
                default => null,
            };
        }

        if ($user->managesDealership() && ! $user->concessionaire_id && $user->dealership?->organization_type === 'head_office' && ($this instanceof Client || $this instanceof Order || $this instanceof Receipt || $this instanceof Payment)) {
            return $query->where($this->getTable().'.user_id', $user->id);
        }

        return $user->canManageBusiness() ? $query : $query->where($this->getTable().'.user_id', $user->id);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
