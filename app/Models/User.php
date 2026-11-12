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

#[Fillable(['name', 'email', 'password', 'username', 'role_id', 'active', 'last_login_at', 'dealership_id', 'client_id', 'concessionaire_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->isAdmin() && ! $user->isCustomer() && ! $user->isHeadOffice() && ! $user->dealership_id) {
                $user->dealership_id = Dealership::orderBy('id')->value('id');
            }
        });
    }

    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function concessionaire(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'concessionaire_id');
    }

    public function isHeadOffice(): bool
    {
        return $this->role?->name === 'head_office';
    }

    public function isCustomer(): bool
    {
        return $this->role?->name === 'customer';
    }

    public function getRoleLabelAttribute(): string
    {
        return Role::LABELS[$this->role?->name] ?? 'Asesor anterior';
    }

    public function canOperateFinancial(): bool
    {
        return in_array($this->role?->name, ['admin', 'dealership_admin', 'user'], true);
    }

    public function hasActiveAccess(): bool
    {
        if (! $this->active || ! in_array($this->role?->name, [...array_keys(Role::LABELS), 'user'], true)) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if (! $this->dealership?->active || ($this->dealership->parent_id && ! $this->dealership->parent?->active)) {
            return false;
        }
        if ($this->isHeadOffice()) {
            return $this->dealership->organization_type === 'head_office';
        }

        if (! $this->isCustomer()) {
            return ! $this->concessionaire_id || ($this->concessionaire?->active && $this->concessionaire->customer_kind === 'dealership' && $this->concessionaire->dealership_id === $this->dealership_id);
        }
        if (! $this->client?->active || $this->client->customer_kind !== 'person' || $this->client->dealership_id !== $this->dealership_id) {
            return false;
        }

        $expectedConcessionaireId = $this->client->concessionaire_id;

        return ! $this->concessionaire_id || ($this->concessionaire?->active && $this->concessionaire->customer_kind === 'dealership'
            && $this->concessionaire->dealership_id === $this->dealership_id && $expectedConcessionaireId === $this->concessionaire_id);
    }

    /** @return array<string, array<string, mixed>> */
    public function workspaceModules(): array
    {
        if ($this->isCustomer()) {
            return ['account' => ['name' => 'Mi cuenta', 'icon' => 'wallet', 'route' => 'portal.account', 'patterns' => ['portal.*']]];
        }
        $modules = config('workspace.modules');
        if ($this->isHeadOffice()) {
            $modules['settings']['description'] = 'Administra los accesos de tus concesionarios y consulta tu cuenta.';
            $modules['reports']['description'] = 'Consulta y exporta ventas, cobros y saldos consolidados por concesionario.';

            return [
                'head-office' => ['name' => 'Contactos', 'icon' => 'users-round', 'description' => 'Registra tus concesionarios, consulta sus fichas y revisa sus resultados.', 'category' => 'Relaciones comerciales', 'route' => 'head-office.overview', 'patterns' => ['head-office.overview', 'head-office.clients*', 'clients.*'], 'children' => [['name' => 'Resumen', 'route' => 'head-office.overview'], ['name' => 'Concesionarios', 'route' => 'clients.index'], ['name' => 'Clientes', 'route' => 'head-office.clients']]],
                'products' => $modules['products'],
                'payments' => ['name' => 'Ventas', 'icon' => 'shopping-bag', 'description' => 'Consulta las ventas de los clientes de tus concesionarios y revisa sus pagos.', 'category' => 'Gestión comercial', 'route' => 'head-office.sales.overview', 'patterns' => ['head-office.sales.*', 'head-office.orders.*', 'head-office.receipts.*', 'receipts.*', 'remittances.*', 'payment-methods.*'], 'children' => [['name' => 'Resumen', 'route' => 'head-office.sales.overview'], ['name' => 'Ventas', 'route' => 'head-office.orders.index'], ['name' => 'Pagos', 'route' => 'receipts.index', 'patterns' => ['receipts.*', 'remittances.*', 'head-office.receipts.*', 'payment-methods.*'], 'children' => [['name' => 'Pagos', 'route' => 'receipts.index'], ['name' => 'Remesas', 'route' => 'remittances.index'], ['name' => 'Métodos de pago', 'route' => 'payment-methods.index', 'payment_methods' => true]]]]],
                'reports' => $modules['reports'],
                'settings' => $modules['settings'],
            ];
        }
        if ($this->managesDealership()) {
            $modules['products']['description'] = 'Consulta el catálogo de productos y sus resultados en tus ventas.';
        }
        if (! $this->isAdmin()) {
            foreach ($modules as &$module) {
                if (isset($module['children'])) {
                    $module['children'] = array_values(array_filter($module['children'], fn (array $child): bool => empty($child['system'])));
                    if (! $this->can('viewAny', Remittance::class)) {
                        foreach ($module['children'] as &$child) {
                            if (isset($child['children'])) {
                                $child['children'] = array_values(array_filter($child['children'], fn (array $link): bool => $link['route'] !== 'remittances.index'));
                            }
                        }
                        unset($child);
                    }
                }
            }
        }

        return $modules;
    }

    public function managesDealership(): bool
    {
        return $this->role?->name === 'dealership_admin';
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin() || $this->isHeadOffice();
    }

    public function canManageBusiness(): bool
    {
        return $this->isAdmin() || $this->managesDealership();
    }

    public function canManagePaymentMethods(): bool
    {
        return ($this->isAdmin() || $this->isHeadOffice()) && $this->hasActiveAccess();
    }

    public function canManageOrganizations(): bool
    {
        return $this->isAdmin();
    }

    public function canAccessRecord(OwnedModel $record): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if (! $this->hasActiveAccess() || $record->dealership_id !== $this->dealership_id) {
            return false;
        }
        if ($this->isHeadOffice()) {
            if ($record instanceof Receipt) {
                return $record->client?->customer_kind === 'person'
                    && $record->client->dealership_id === $this->dealership_id
                    && $record->client->concessionaire?->customer_kind === 'dealership'
                    && $record->client->concessionaire->dealership_id === $this->dealership_id;
            }

            return $record instanceof Client && $record->customer_kind === 'dealership';
        }
        if ($this->isCustomer()) {
            return match (true) {
                $record instanceof Client => $record->id === $this->client_id,
                $record instanceof Order, $record instanceof Receipt => $record->client_id === $this->client_id,
                $record instanceof Payment => $record->order?->client_id === $this->client_id,
                default => false,
            };
        }

        if ($this->concessionaire_id) {
            $withinConcessionaire = match (true) {
                $record instanceof Client => $record->customer_kind === 'person' && $record->concessionaire_id === $this->concessionaire_id,
                $record instanceof Order, $record instanceof Receipt => $record->client && $this->canAccessRecord($record->client),
                $record instanceof Payment => $record->order && $this->canAccessRecord($record->order),
                default => true,
            };
            if (! $withinConcessionaire) {
                return false;
            }
        } elseif ($this->managesDealership() && $this->dealership?->organization_type === 'head_office' && ($record instanceof Client || $record instanceof Order || $record instanceof Receipt || $record instanceof Payment)) {
            return $record->user_id === $this->id;
        }

        return $this->managesDealership() || $record->user_id === $this->id;
    }

    public function scopeManagedBy(Builder $query, User $actor): Builder
    {
        $id = DealershipContext::id($actor);
        if ($id) {
            $query->where('dealership_id', $id);
        }
        if (! $actor->isAdmin()) {
            $query->where('dealership_id', $actor->dealership_id)->whereHas('role', fn ($q) => $q->where('name', '!=', 'admin'));
            if ($actor->isHeadOffice()) {
                return $query->whereHas('role', fn (Builder $roles) => $roles->where('name', 'dealership_admin'));
            }
            if ($actor->concessionaire_id) {
                $query->where('concessionaire_id', $actor->concessionaire_id);
            } elseif ($actor->managesDealership() && $actor->dealership?->organization_type === 'head_office') {
                $query->whereKey($actor->id);
            }
        }

        return $query;
    }

    public function scopeAssignableBy(Builder $query, User $actor): Builder
    {
        $query->whereHas('role', fn ($role) => $role->whereIn('name', ['admin', 'dealership_admin', 'user']));
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
            'dealership_id' => 'integer', 'client_id' => 'integer', 'concessionaire_id' => 'integer',
            'role_id' => 'integer',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
