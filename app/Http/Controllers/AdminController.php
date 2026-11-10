<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Dealership;
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

    private function checkUser(Request $request, User $user): void
    {
        $this->check($request);
        if (! $request->user()->isAdmin()) {
            abort_unless($user->dealership_id === $request->user()->dealership_id && $user->managesDealership(), 403);
        }
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

        return view('admin.user-detail', $this->userFormData($r, new User(['active' => true])));
    }

    public function showUser(Request $request, User $user): View
    {
        $this->checkUser($request, $user);

        $request->session()->forget('_old_input');

        return view('admin.user-detail', $this->userFormData($request, $user) + ['readOnly' => true]);
    }

    public function editUser(Request $r, User $user): View
    {
        $this->checkUser($r, $user);

        return view('admin.user-detail', $this->userFormData($r, $user));
    }

    private function userFormData(Request $request, User $user): array
    {
        $allowed = $request->user()->isAdmin() ? array_keys(Role::LABELS) : ['dealership_admin'];
        if ($user->exists && $user->role?->name === 'user') {
            $allowed[] = 'user';
        }
        $roles = Role::whereIn('name', $allowed)->get();
        $companies = Dealership::query()->when(! $request->user()->isAdmin(), fn ($query) => $query->whereKey($request->user()->dealership_id))->orderBy('name')->get();
        $clients = Client::where('customer_kind', 'person')->where('active', true)->when($request->user()->isHeadOffice(), fn ($query) => $query->whereRaw('1=0'))
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->where('dealership_id', $request->user()->dealership_id))
            ->orderBy('name')->get(['id', 'name', 'document', 'customer_kind', 'dealership_id', 'concessionaire_id']);
        $concessionaires = Client::where('customer_kind', 'dealership')
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->where('dealership_id', $request->user()->dealership_id))
            ->where(fn ($query) => $query->where('active', true)->orWhereKey($user->concessionaire_id))
            ->orderBy('name')->get(['id', 'name', 'document', 'dealership_id', 'active']);

        return ['u' => $user, 'roles' => $roles, 'companies' => $companies, 'accountClients' => $clients, 'accountConcessionaires' => $concessionaires];
    }

    public function saveUser(Request $r, ?User $user = null): RedirectResponse
    {
        $this->check($r);
        $user ??= new User;
        if ($user->exists) {
            $this->checkUser($r, $user);
        }
        $v = $r->validate(['name' => 'required|string|max:255', 'username' => ['required', 'alpha_dash', 'max:80', Rule::unique('users')->ignore($user->id)], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'password' => [$user->exists ? 'nullable' : 'required', 'string', 'min:8', 'max:100'], 'role_id' => 'required|exists:roles,id', 'active' => 'required|boolean', 'dealership_id' => 'nullable|integer|exists:dealerships,id', 'client_id' => ['nullable', 'integer', 'exists:clients,id', Rule::unique('users', 'client_id')->ignore($user->id)]]);
        $role = Role::findOrFail($v['role_id']);
        $r->validate(['relate_contact' => 'sometimes|boolean']);
        $createContact = ! $user->exists && in_array($role->name, ['dealership_admin', 'customer'], true) && ! $r->boolean('relate_contact', true);
        $concessionaireValues = $r->validate(['concessionaire_id' => 'nullable|integer|exists:clients,id']);
        $v['concessionaire_id'] = in_array($role->name, ['dealership_admin', 'user', 'customer'], true)
            ? ($concessionaireValues['concessionaire_id'] ?? ($r->has('concessionaire_id') ? null : $user->concessionaire_id))
            : null;
        $allowedRoles = $r->user()->isAdmin() ? array_keys(Role::LABELS) : ['dealership_admin'];
        if ($user->exists && $user->role?->name === 'user') {
            $allowedRoles[] = 'user';
        }
        abort_unless(in_array($role->name, $allowedRoles, true), 403);
        if ($r->user()->isHeadOffice()) {
            abort_if($r->filled('client_id') || ($r->filled('dealership_id') && $r->integer('dealership_id') !== $r->user()->dealership_id), 403);
        }
        if ($createContact) {
            if ($r->filled('client_id') || ($role->name === 'dealership_admin' && $r->filled('concessionaire_id'))) {
                throw ValidationException::withMessages(['relate_contact' => 'Selecciona Usar un contacto existente para relacionar una ficha ya registrada.']);
            }
            if ($role->name === 'customer') {
                $contact = Client::where('customer_kind', 'dealership')->where('active', true)->find($v['concessionaire_id']);
                if (! $contact) {
                    throw ValidationException::withMessages(['concessionaire_id' => 'Selecciona el concesionario del nuevo cliente.']);
                }
                if (! empty($v['dealership_id']) && (int) $v['dealership_id'] !== $contact->dealership_id) {
                    throw ValidationException::withMessages(['concessionaire_id' => 'El concesionario seleccionado no pertenece a la empresa asignada.']);
                }
                $v['dealership_id'] = $contact->dealership_id;
            } else {
                $v['dealership_id'] = DealershipContext::forContactWrite($r->user(), ! empty($v['dealership_id']) ? (int) $v['dealership_id'] : null);
            }
        }
        if ($role->name === 'dealership_admin' && $v['concessionaire_id']) {
            $contact = Client::where('customer_kind', 'dealership')->when(! $r->user()->isAdmin(), fn ($query) => $query->where('dealership_id', $r->user()->dealership_id))->find($v['concessionaire_id']);
            if (! $contact || (! $contact->active && $user->concessionaire_id !== $contact->id)) {
                throw ValidationException::withMessages(['concessionaire_id' => 'Selecciona un contacto activo de tipo Concesionario.']);
            }
            if (! empty($v['dealership_id']) && (int) $v['dealership_id'] !== $contact->dealership_id) {
                throw ValidationException::withMessages(['concessionaire_id' => 'El concesionario seleccionado no pertenece a la empresa asignada.']);
            }
            $v['dealership_id'] = $contact->dealership_id;
        }
        if ($role->name === 'customer' && ! $createContact) {
            $accountClient = Client::where('customer_kind', 'person')->where('active', true)->find($r->input('client_id'));
            if (! $accountClient) {
                throw ValidationException::withMessages(['client_id' => 'Selecciona la ficha de un cliente activo.']);
            }
            $expectedConcessionaireId = $accountClient->concessionaire_id;
            $v['concessionaire_id'] = $r->filled('concessionaire_id') ? $r->integer('concessionaire_id') : $expectedConcessionaireId;
            if ($v['concessionaire_id']) {
                $contact = Client::where('customer_kind', 'dealership')->where('active', true)->find($v['concessionaire_id']);
                if (! $contact) {
                    throw ValidationException::withMessages(['concessionaire_id' => 'Selecciona un registro activo de tipo Concesionario en Clientes.']);
                }
                if ($expectedConcessionaireId !== $contact->id || $accountClient->dealership_id !== $contact->dealership_id) {
                    throw ValidationException::withMessages(['client_id' => 'El cliente debe estar relacionado con el concesionario seleccionado.']);
                }
            }
            $v['dealership_id'] = ($v['dealership_id'] ?? null) ?: $accountClient->dealership_id;
        }
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
            $v['dealership_id'] = DealershipContext::forContactWrite($r->user(), (int) $v['dealership_id']);
        }
        $v['client_id'] = null;
        if ($role->name === 'customer' && ! $createContact) {
            $client = Client::where('customer_kind', 'person')->where('active', true)->where('dealership_id', $v['dealership_id'])->find($r->input('client_id'));
            if (! $client) {
                throw ValidationException::withMessages(['client_id' => 'Selecciona un cliente activo del concesionario asignado.']);
            }
            $v['client_id'] = $client->id;
        }
        if (($role->name === 'head_office' || ($role->name === 'dealership_admin' && ! $v['concessionaire_id'] && ! $createContact)) && (! $user->exists || $user->role_id !== $role->id || $user->dealership_id !== (int) $v['dealership_id'])) {
            $expectedType = $role->name === 'head_office' ? 'head_office' : 'concessionaire';
            if (! Dealership::whereKey($v['dealership_id'])->where('organization_type', $expectedType)->exists()) {
                throw ValidationException::withMessages(['dealership_id' => $role->name === 'head_office' ? 'Selecciona una casa matriz.' : 'Selecciona un concesionario.']);
            }
        }
        if ($createContact && Client::where('dealership_id', $v['dealership_id'])->where('customer_kind', $role->name === 'customer' ? 'person' : 'dealership')->where('email', $v['email'])->exists()) {
            throw ValidationException::withMessages(['relate_contact' => 'Ya existe un contacto con este correo. Selecciona Usar un contacto existente para evitar duplicarlo.']);
        }
        DB::transaction(function () use ($r, $user, $v, $role, $createContact) {
            if ($v['dealership_id']) {
                Dealership::where('active', true)->lockForUpdate()->findOrFail($v['dealership_id']);
            }
            if ($v['client_id']) {
                $linked = Client::lockForUpdate()->findOrFail($v['client_id']);
                $expectedConcessionaireId = $linked->concessionaire_id;
                if (! $linked->active || $linked->customer_kind !== 'person' || $linked->dealership_id !== (int) $v['dealership_id']
                    || $expectedConcessionaireId !== $v['concessionaire_id']) {
                    throw ValidationException::withMessages(['client_id' => 'La ficha del cliente cambió. Revisa su relación con el concesionario.']);
                }
            }
            if ($v['concessionaire_id']) {
                $concessionaire = Client::lockForUpdate()->findOrFail($v['concessionaire_id']);
                if ($concessionaire->customer_kind !== 'dealership' || $concessionaire->dealership_id !== (int) $v['dealership_id']
                    || (! $concessionaire->active && $user->concessionaire_id !== $concessionaire->id)) {
                    throw ValidationException::withMessages(['concessionaire_id' => 'Selecciona un concesionario activo de Contactos que pertenezca a la empresa asignada.']);
                }
            }
            $admins = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->where('active', true)->lockForUpdate()->get();
            if ($user->exists) {
                $locked = User::lockForUpdate()->findOrFail($user->id);
                if (! $r->user()->isAdmin()) {
                    abort_unless($locked->dealership_id === $r->user()->dealership_id && $locked->managesDealership(), 403);
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
            $before = $user->only(['name', 'email', 'username', 'role_id', 'active', 'dealership_id', 'client_id', 'concessionaire_id']);
            $user->fill($v);
            $user->save();
            if ($createContact) {
                $contact = Client::create(['user_id' => $role->name === 'dealership_admin' ? $user->id : $r->user()->id,
                    'dealership_id' => $user->dealership_id, 'name' => $user->name, 'email' => $user->email,
                    'customer_kind' => $role->name === 'dealership_admin' ? 'dealership' : 'person',
                    'concessionaire_id' => $role->name === 'customer' ? $user->concessionaire_id : null,
                    'document' => '', 'phone' => '', 'active' => $user->active]);
                $user->update($role->name === 'dealership_admin' ? ['concessionaire_id' => $contact->id] : ['client_id' => $contact->id]);
                Audit::log($r->user()->id, 'save', 'client', $contact->id, ['after' => $contact->getAttributes(), 'created_with_user_id' => $user->id]);
            }
            Audit::log($r->user()->id, 'save', 'user', $user->id, ['before' => $before, 'after' => $user->only(['name', 'email', 'username', 'role_id', 'active', 'dealership_id', 'client_id', 'concessionaire_id']), 'password_reset' => isset($v['password'])]);
        }, 3);

        return redirect()->route('users.edit', $user)->with('success', 'Usuario guardado.');
    }

    public function audit(Request $r): View
    {
        abort_unless($r->user()->canManageBusiness(), 403);
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
