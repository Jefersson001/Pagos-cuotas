<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\User;
use App\Services\Audit;
use App\Services\DealershipContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(Request $r)
    {
        $q = Client::visibleTo($r->user())->with('owner');
        if ($r->filled('q')) {
            $s = '%'.$r->string('q')->toString().'%';
            $q->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('document', 'like', $s)->orWhere('phone', 'like', $s));
        }

        return view('clients.index', ['clients' => $q->latest()->paginate(15)->withQueryString()]);
    }

    public function create(Request $r)
    {
        return view('clients.form', $this->formData($r, new Client));
    }

    public function edit(Request $r, Client $client)
    {
        Gate::authorize('update', $client);

        return view('clients.form', $this->formData($r, $client));
    }

    private function formData(Request $r, Client $client): array
    {
        $selectedDealershipId = $client->dealership_id ?? ($r->user()->isAdmin() ? (int) $r->old('dealership_id', DealershipContext::id($r->user()) ?? Dealership::where('active', true)->orderBy('id')->value('id')) : $r->user()->dealership_id);
        $users = $r->user()->canManageUsers() ? User::where('dealership_id', $selectedDealershipId)->where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        return compact('client', 'users', 'selectedDealershipId');
    }

    public function responsibleUsers(Request $r): JsonResponse
    {
        abort_unless($r->user()->canManageUsers(), 403);
        $values = $r->validate(['dealership_id' => 'required|integer|exists:dealerships,id']);
        $id = (int) $values['dealership_id'];
        abort_unless($r->user()->isAdmin() || $r->user()->dealership_id === $id, 403);
        Dealership::where('active', true)->findOrFail($id);

        return response()->json(['users' => User::where('dealership_id', $id)->where('active', true)->orderBy('name')->get(['id', 'name'])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Client $client)
    {
        Gate::authorize('view', $client);
        $client->load(['receipts.method', 'receipts.applications', 'orders.payments.method', 'orders.installments.allocations.payment']);

        return view('clients.show', compact('client'));
    }

    public function store(Request $r)
    {
        return $this->save($r, new Client);
    }

    public function update(Request $r, Client $client)
    {
        Gate::authorize('update', $client);

        return $this->save($r, $client);
    }

    private function save(Request $r, Client $client): RedirectResponse
    {
        if ($r->user()->canManageUsers()) {
            $r->validate(['user_id' => 'required|integer']);
        }
        $owner = $r->user()->canManageUsers() ? (int) $r->input('user_id', $client->user_id ?? $r->user()->id) : $r->user()->id;
        if ($r->user()->managesDealership()) {
            abort_unless(User::managedBy($r->user())->whereKey($owner)->exists(), 403);
        }
        $r->merge(['whatsapp' => $this->normalizeWhatsApp($r->input('whatsapp'))]);
        $v = $r->validate(['name' => 'required|string|max:255', 'document' => ['required', 'string', 'max:80', Rule::unique('clients')->where('user_id', $owner)->ignore($client->id)], 'phone' => 'required|string|max:40', 'whatsapp' => 'nullable|string|regex:/^[0-9]{7,20}$/', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:3000', 'active' => 'required|boolean', 'dealership_id' => 'nullable|integer|exists:dealerships,id'], ['whatsapp.regex' => 'Escribe un WhatsApp válido, por ejemplo +58 412 1234567 o 0412-1234567.']);
        DB::transaction(function () use ($v, $owner, $client, $r) {
            if ($client->exists) {
                $locked = Client::lockForUpdate()->findOrFail($client->id);
                Gate::forUser($r->user())->authorize('update', $locked);
                $client->setRawAttributes($locked->getAttributes(), true);
            }
            $responsible = User::lockForUpdate()->findOrFail($owner);
            if (! $responsible->active) {
                throw ValidationException::withMessages(['user_id' => 'El responsable está inactivo.']);
            }
            $requested = $r->user()->isAdmin() ? ($v['dealership_id'] ?? $responsible->dealership_id) : null;
            $dealerId = DealershipContext::forWrite($r->user(), $requested ? (int) $requested : null, $client->exists ? $client->dealership_id : null);
            if (! $r->user()->isAdmin()) {
                abort_unless($responsible->dealership_id === $dealerId && ! $responsible->isAdmin(), 403);
            }
            if ($responsible->dealership_id !== null && $responsible->dealership_id !== $dealerId) {
                throw ValidationException::withMessages(['user_id' => 'El responsable debe pertenecer al mismo concesionario que el cliente.']);
            }
            $before = $client->getOriginal();
            unset($v['dealership_id']);
            $client->fill($v + ['user_id' => $owner, 'dealership_id' => $dealerId]);
            $client->save();
            foreach ($client->orders()->lockForUpdate()->get() as $order) {
                $order->update(['user_id' => $owner]);
                $order->payments()->update(['user_id' => $owner]);
            }
            $client->receipts()->update(['user_id' => $owner]);
            Audit::log($r->user()->id, 'save', 'client', $client->id, ['before' => $before, 'after' => $client->getAttributes()]);
        }, 3);

        return redirect()->route('clients.show', $client)->with('success', 'Cliente guardado.');
    }

    private function normalizeWhatsApp(mixed $value): mixed
    {
        if (! is_string($value) || ! preg_match('/^[+0-9\s().\-]*$/u', $value)) {
            return $value;
        }
        $number = preg_replace('/[\s().\-]+/u', '', $value);
        if ($number === '') {
            return null;
        }
        if (! preg_match('/^\+?[0-9]{7,20}$/D', $number)) {
            return $value;
        }
        $number = ltrim($number, '+');
        if (str_starts_with($number, '00')) {
            $number = substr($number, 2);
        }
        if (preg_match('/^0[24][0-9]{9}$/D', $number)) {
            $number = '58'.substr($number, 1);
        }

        return $number;
    }
}
