<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Services\DealershipContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    private function check(Request $r): void
    {
        abort_unless($r->user()->canManageUsers(), 403);
    }

    public function users(Request $r): View
    {
        $this->check($r);
        $r->validate(['q' => 'nullable|string|max:255', 'active' => 'nullable|in:0,1']);
        $query = User::managedBy($r->user())->with(['role', 'dealership'])->withCount('orders');
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('username', 'like', $term));
        }
        if ($r->filled('active')) {
            $query->where('active', $r->input('active'));
        }

        return view('admin.users', ['users' => $query->orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function createUser(Request $r): View
    {
        $this->check($r);

        return view('admin.user-detail', ['u' => new User(['active' => true]), 'roles' => Role::when(! $r->user()->isAdmin(), fn ($q) => $q->where('name', '!=', 'admin'))->get()]);
    }

    public function editUser(Request $r, User $user): View
    {
        $this->check($r);
        if (! $r->user()->isAdmin()) {
            abort_unless($user->dealership_id === $r->user()->dealership_id && ! $user->isAdmin(), 403);
        }

        return view('admin.user-detail', ['u' => $user, 'roles' => Role::when(! $r->user()->isAdmin(), fn ($q) => $q->where('name', '!=', 'admin'))->get()]);
    }

    public function saveUser(Request $r, ?User $user = null): RedirectResponse
    {
        $this->check($r);
        $user ??= new User;
        if ($user->exists && ! $r->user()->isAdmin()) {
            abort_unless($user->dealership_id === $r->user()->dealership_id && ! $user->isAdmin(), 403);
        }
        $v = $r->validate(['name' => 'required|string|max:255', 'username' => ['required', 'alpha_dash', 'max:80', Rule::unique('users')->ignore($user->id)], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'password' => [$user->exists ? 'nullable' : 'required', 'string', 'min:12', 'max:100'], 'role_id' => 'required|exists:roles,id', 'active' => 'required|boolean', 'dealership_id' => 'nullable|integer|exists:dealerships,id']);
        $role = Role::findOrFail($v['role_id']);
        if (! $r->user()->isAdmin()) {
            abort_if($role->name === 'admin', 403);
            $v['dealership_id'] = $r->user()->dealership_id;
        } elseif ($role->name === 'admin') {
            $v['dealership_id'] = null;
        } else {
            if (empty($v['dealership_id'])) {
                throw ValidationException::withMessages(['dealership_id' => 'Selecciona el concesionario del usuario.']);
            }
        }
        if ($v['dealership_id']) {
            $v['dealership_id'] = DealershipContext::forWrite($r->user(), (int) $v['dealership_id']);
        }
        DB::transaction(function () use ($r, $user, $v, $role) {
            $admins = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->where('active', true)->lockForUpdate()->get();
            if ($user->exists) {
                $locked = User::lockForUpdate()->findOrFail($user->id);
                if (! $r->user()->isAdmin()) {
                    abort_unless($locked->dealership_id === $r->user()->dealership_id && ! $locked->isAdmin(), 403);
                }
                $user->setRawAttributes($locked->getAttributes(), true);
                $user->unsetRelation('role');
                if ($admins->contains('id', $user->id) && $admins->count() === 1 && (! $v['active'] || $role->name !== 'admin')) {
                    throw ValidationException::withMessages(['role_id' => 'Debe quedar al menos un administrador general activo.']);
                }
                if ($user->managesDealership() && (! $v['active'] || $role->name !== 'dealership_admin' || $user->dealership_id !== $v['dealership_id'])) {
                    $managers = User::where('dealership_id', $user->dealership_id)->where('active', true)->whereHas('role', fn ($q) => $q->where('name', 'dealership_admin'))->lockForUpdate()->get();
                    if (! $r->user()->isAdmin() && $managers->count() === 1) {
                        throw ValidationException::withMessages(['role_id' => 'Debe quedar un administrador activo en el concesionario.']);
                    }
                }
                if ($user->dealership_id !== $v['dealership_id'] && (Client::where('user_id', $user->id)->exists() || Order::where('user_id', $user->id)->exists() || Payment::where('user_id', $user->id)->exists() || DB::table('products')->where('user_id', $user->id)->exists())) {
                    throw ValidationException::withMessages(['dealership_id' => 'El usuario tiene operaciones asociadas. Reasigna su cartera dentro del concesionario antes de cambiarlo.']);
                }
            }
            if (empty($v['password'])) {
                unset($v['password']);
            }
            $before = $user->only(['name', 'email', 'username', 'role_id', 'active', 'dealership_id']);
            $user->fill($v);
            $user->save();
            Audit::log($r->user()->id, 'save', 'user', $user->id, ['before' => $before, 'after' => $user->only(['name', 'email', 'username', 'role_id', 'active', 'dealership_id']), 'password_reset' => isset($v['password'])]);
        }, 3);

        return redirect()->route('users.edit', $user)->with('success', 'Usuario guardado.');
    }

    public function audit(Request $r): View
    {
        $this->check($r);
        $query = DB::table('audit_logs')->join('users', 'users.id', '=', 'audit_logs.user_id')->leftJoin('dealerships', 'dealerships.id', '=', 'audit_logs.dealership_id')->select('audit_logs.*', 'users.name', 'dealerships.name as dealership_name');
        if ($id = DealershipContext::id($r->user())) {
            $query->where('audit_logs.dealership_id', $id);
        }
        if ($r->filled('user_id')) {
            $query->where('audit_logs.user_id', $r->integer('user_id'));
        }

        return view('admin.audit', ['logs' => $query->orderByDesc('audit_logs.id')->paginate(30)->withQueryString()]);
    }
}
