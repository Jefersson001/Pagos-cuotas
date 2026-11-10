<?php

namespace App\Services;

use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsOverviewService
{
    public const ROLE_LABELS = Role::LABELS + ['user' => 'Asesor anterior'];

    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            $actor = $request->user();
            $now = now();
            $monthStart = $now->copy()->startOfMonth();
            $weekStart = $now->copy()->startOfDay()->subDays(6);
            $companyId = DealershipContext::id($actor);
            $data = ['updatedAt' => $now, 'monthStart' => $monthStart, 'weekStart' => $weekStart, 'company' => $companyId ? Dealership::findOrFail($companyId) : null, 'contextParams' => $companyId ? ['dealership_id' => $companyId] : [], 'roleLabel' => self::ROLE_LABELS[$actor->role?->name] ?? 'Usuario'];
            if (! $actor->canManageBusiness()) {
                return $data;
            }
            $users = User::managedBy($actor)->with(['role', 'dealership'])->get();
            $activeUsers = $users->where('active', true);
            $recentUsers = $activeUsers->filter(fn (User $user): bool => $user->last_login_at?->betweenIncluded($weekStart, $now) ?? false);
            $methods = PaymentMethod::visibleTo($actor)->with('dealership')->withCount(['receipts as confirmed_month_count' => fn ($query) => $query->visibleTo($actor)->where('approval_status', 'approved')->where('approval_status', 'approved')->whereNull('voided_at')->whereBetween('created_at', [$monthStart, $now])])->get();
            $logs = DB::table('audit_logs')->join('users', 'users.id', '=', 'audit_logs.user_id')
                ->when($companyId, fn ($query) => $query->where('audit_logs.dealership_id', $companyId))->where('audit_logs.created_at', '<=', $now);
            if (! $actor->isAdmin() && ($actor->concessionaire_id || $actor->dealership?->organization_type === 'head_office')) {
                $logs->whereIn('audit_logs.user_id', $users->modelKeys());
            }
            $roles = collect(self::ROLE_LABELS)->filter(fn (string $label, string $role): bool => $role !== 'user' || $users->contains(fn (User $user): bool => $user->role?->name === 'user'))->filter(fn (string $label, string $role): bool => $actor->isAdmin() || $role !== 'admin')->map(function (string $label, string $role) use ($users): array {
                $members = $users->filter(fn (User $user): bool => $user->role?->name === $role);

                return ['label' => $label, 'total' => $members->count(), 'active' => $members->where('active', true)->count()];
            });

            return $data + [
                'usersTotal' => $users->count(), 'usersActive' => $activeUsers->count(), 'usersInactive' => $users->where('active', false)->count(),
                'usersNewMonth' => $users->filter(fn (User $user): bool => $user->created_at->betweenIncluded($monthStart, $now))->count(),
                'usersNeverLoggedIn' => $activeUsers->whereNull('last_login_at')->count(), 'usersRecentCount' => $recentUsers->count(),
                'recentUsers' => $recentUsers->sortByDesc('last_login_at')->take(5)->values(), 'roles' => $roles,
                'methodsTotal' => $methods->count(), 'methodsActive' => $methods->where('active', true)->count(), 'methodsInactive' => $methods->where('active', false)->count(),
                'methodsUsedMonth' => $methods->where('confirmed_month_count', '>', 0)->count(),
                'methods' => $methods->sort(fn (PaymentMethod $left, PaymentMethod $right): int => ($right->confirmed_month_count <=> $left->confirmed_month_count) ?: strcasecmp($left->name, $right->name))->take(5)->values(),
                'auditMonth' => (clone $logs)->where('audit_logs.created_at', '>=', $monthStart)->count(),
                'auditToday' => (clone $logs)->where('audit_logs.created_at', '>=', $now->copy()->startOfDay())->count(),
                'recentLogs' => (clone $logs)->select(['audit_logs.id', 'audit_logs.created_at', 'audit_logs.action', 'audit_logs.entity', 'audit_logs.entity_id', 'users.name as actor_name'])->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id')->limit(5)->get(),
            ];
        });
    }
}
