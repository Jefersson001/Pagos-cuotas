<?php

namespace App\Models;

use App\Services\DealershipContext;
use Database\Factories\SalesEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SalesEvent extends Model
{
    /** @use HasFactory<SalesEventFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dealership_id' => 'integer', 'active' => 'boolean', 'is_default' => 'boolean'];
    }

    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if (! $actor->hasActiveAccess() || $actor->isCustomer()) {
            return $query->whereRaw('1=0');
        }
        $query->whereHas('dealership');
        $companyId = DealershipContext::id($actor);

        return $companyId ? $query->where('dealership_id', $companyId) : ($actor->isAdmin() ? $query : $query->whereRaw('1=0'));
    }

    public static function defaultForCompany(int $companyId): self
    {
        return DB::transaction(function () use ($companyId): self {
            Dealership::lockForUpdate()->findOrFail($companyId);
            $normal = self::where('dealership_id', $companyId)->where('is_default', true)->first();
            if ($normal) {
                return $normal;
            }

            return self::updateOrCreate(['dealership_id' => $companyId, 'name' => 'Jornada normal'], ['is_default' => true, 'active' => true]);
        }, 3);
    }
}
