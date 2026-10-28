<?php

namespace App\Models;

use App\Services\DealershipContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends OwnedModel
{
    use HasFactory;

    public const TRANSMISSIONS = ['sincronico' => 'Sincrónico', 'automatico' => 'Automático'];

    public const COLORS = [
        'gris' => ['label' => 'Gris', 'hex' => '#dedede'],
        'blanco' => ['label' => 'Blanco', 'hex' => '#ffffff'],
        'plata' => ['label' => 'Plata', 'hex' => '#bfbdbd'],
        'rojo' => ['label' => 'Rojo', 'hex' => '#ff0000'],
        'negro' => ['label' => 'Negro', 'hex' => '#000000'],
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'cash_price' => 'decimal:2', 'initial_amount' => 'decimal:2', 'year' => 'integer', 'installment_count' => 'integer', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (Product $product): void {
            if ($product->brand && $product->model) {
                $product->name = implode(' ', array_filter([$product->brand, $product->model, $product->year, self::TRANSMISSIONS[$product->transmission] ?? null, self::COLORS[$product->color]['label'] ?? null]));
            }
        });
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
