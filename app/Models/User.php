<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\DealershipContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'username', 'role_id', 'active', 'last_login_at', 'dealership_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->isAdmin() && ! $user->dealership_id) {
                $user->dealership_id = Dealership::orderBy('id')->value('id');
            }
        });
    }

    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    public function managesDealership(): bool
    {
        return $this->role?->name === 'dealership_admin';
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin() || $this->managesDealership();
    }

    public function canAccessRecord(OwnedModel $record): bool
    {
        return $this->isAdmin() || ($this->dealership_id !== null && $this->dealership_id === $record->dealership_id && $this->dealership?->active && ($this->managesDealership() || $record->user_id === $this->id));
    }

    public function scopeManagedBy(Builder $query, User $actor): Builder
    {
        $id = DealershipContext::id($actor);
        if ($id) {
            $query->where('dealership_id', $id);
        }
        if (! $actor->isAdmin()) {
            $query->where('dealership_id', $actor->dealership_id)->whereHas('role', fn ($q) => $q->where('name', '!=', 'admin'));
        }

        return $query;
    }

    public function scopeAssignableBy(Builder $query, User $actor): Builder
    {
        if (! $actor->isAdmin()) {
            return $query->managedBy($actor);
        }
        $id = DealershipContext::id($actor);
        if ($id) {
            $query->where(fn ($q) => $q->where('dealership_id', $id)->orWhere(fn ($q) => $q->whereNull('dealership_id')->whereHas('role', fn ($role) => $role->where('name', 'admin'))));
        }

        return $query;
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dealership_id' => 'integer',
            'role_id' => 'integer',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
