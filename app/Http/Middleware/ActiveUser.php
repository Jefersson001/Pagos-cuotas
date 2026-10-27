<?php

namespace App\Http\Middleware;

use App\Models\Dealership;
use App\Services\DealershipContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

class ActiveUser
{
    public function handle(Request $r, Closure $next)
    {
        $user = $r->user()?->fresh();
        if (! $user?->active || (! $user->isAdmin() && ! $user->dealership?->active)) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect()->route('login');
        }Auth::setUser($user);
        if ($user->isAdmin() && $r->isMethod('GET') && $r->query->has('dealership_id')) {
            Validator::make($r->query(), ['dealership_id' => 'nullable|integer|exists:dealerships,id'])->validate();
        }
        $dealershipId = DealershipContext::id($user);
        $currentDealership = $dealershipId ? Dealership::find($dealershipId) : null;
        if ($dealershipId && ! $currentDealership && $user->isAdmin()) {
            $r->session()->forget('dealership_id');
        }
        View::share([
            'dealerships' => $user->isAdmin() ? Dealership::orderBy('name')->get() : collect([$currentDealership])->filter(),
            'currentDealership' => $currentDealership,
            'businessName' => $currentDealership?->name ?? 'Todos los concesionarios',
        ]);

        return $next($r);
    }
}
