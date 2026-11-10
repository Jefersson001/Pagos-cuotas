<?php

namespace App\Services;

use App\Models\Dealership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealershipContext
{
    public static function forContactWrite(User $user, ?int $requested = null, ?int $existing = null): int
    {
        if (! $user->isHeadOffice()) {
            return self::forWrite($user, $requested, $existing);
        }
        abort_unless($user->hasActiveAccess() && (! $requested || $requested === $user->dealership_id) && (! $existing || $existing === $user->dealership_id), 403);
        $organization = (DB::transactionLevel() > 0 ? Dealership::lockForUpdate() : Dealership::query())->findOrFail($user->dealership_id);
        abort_unless($organization->active && $organization->organization_type === 'head_office', 403);

        return $organization->id;
    }

    public static function id(User $user): ?int
    {
        if (! $user->isAdmin()) {
            return $user->dealership_id;
        }
        $request = request();
        if ($request->isMethod('GET') && $request->query->has('dealership_id')) {
            $value = $request->query('dealership_id');

            return $value === null || $value === '' ? null : (int) $value;
        }

        return $request->hasSession() && $request->session()->get('dealership_id') ? (int) $request->session()->get('dealership_id') : null;
    }

    public static function forWrite(User $user, ?int $requested = null, ?int $existing = null): int
    {
        abort_unless($user->canOperateFinancial(), 403);
        if (! $user->isAdmin()) {
            abort_unless($user->dealership_id && (! $requested || $requested === $user->dealership_id) && (! $existing || $existing === $user->dealership_id), 403);
            $id = $user->dealership_id;
        } else {
            $id = $existing ?? $requested ?? self::id($user) ?? Dealership::orderBy('id')->value('id');
        }
        $dealer = (DB::transactionLevel() > 0 ? Dealership::lockForUpdate() : Dealership::query())->findOrFail($id);
        if (! $dealer->active) {
            throw ValidationException::withMessages(['dealership_id' => 'El concesionario está inactivo.']);
        }

        return $dealer->id;
    }
}
