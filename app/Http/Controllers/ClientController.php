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
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(Request $r)
    {
        $q = Client::visibleTo($r->user())->with('owner');
        $r->validate(['q' => 'nullable|string|max:255', 'business_type' => 'nullable|string|max:80', 'active' => 'nullable|in:0,1']);
        if ($r->filled('q')) {
            $s = '%'.$r->string('q')->toString().'%';
            $q->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('document', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('city', 'like', $s)->orWhere('state', 'like', $s)->orWhere('legal_representative', 'like', $s));
        }
        if ($r->filled('business_type')) {
            $q->where('business_type', $r->input('business_type'));
        }
        if ($r->filled('active')) {
            $q->where('active', $r->boolean('active'));
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
        $r->validate(['user_id' => 'sometimes|required|integer']);
        $companyId = $client->dealership_id ?? DealershipContext::id($r->user()) ?? Dealership::where('active', true)->orderBy('id')->value('id');
        $defaultOwner = $client->user_id ?? ($r->user()->canManageUsers()
            ? (User::where('dealership_id', $companyId)->where('active', true)->whereHas('role', fn ($q) => $q->where('name', 'user'))->orderBy('id')->value('id') ?? $r->user()->id)
            : $r->user()->id);
        $owner = $r->user()->canManageUsers() ? (int) $r->input('user_id', $defaultOwner) : $r->user()->id;
        if ($r->user()->managesDealership()) {
            abort_unless(User::managedBy($r->user())->whereKey($owner)->exists(), 403);
        }
        if ($r->has('whatsapp')) {
            $r->merge(['whatsapp' => $this->normalizeWhatsApp($r->input('whatsapp'))]);
        }
        if (! $r->has('active')) {
            $condition = mb_strtoupper(trim((string) $r->input('condition')));
            $r->merge(['active' => $condition === '' ? ($client->active ?? true) : ! in_array($condition, ['INACTIVO', 'INACTIVA'], true)]);
        }
        $v = $r->validate(['name' => 'required|string|max:255', 'document' => 'nullable|string|max:80', 'phone' => 'nullable|string|max:255', 'whatsapp' => 'nullable|string|regex:/^[0-9]{7,20}$/', 'email' => 'nullable|string|max:255', 'address' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:3000', 'active' => 'required|boolean', 'dealership_id' => 'nullable|integer|exists:dealerships,id', 'business_type' => 'nullable|string|max:80', 'condition' => 'nullable|string|max:80', 'list_number' => 'nullable|integer|min:1', 'refrigerators' => 'nullable|integer|min:0', 'refrigerators_assigned_at' => 'nullable|date_format:Y-m-d', 'listed_started_at' => 'nullable|date_format:Y-m-d', 'commercial_registry' => 'nullable|string|max:2000', 'registry_jurisdiction' => 'nullable|string|max:255', 'registry_date' => 'nullable|date_format:Y-m-d', 'registry_number' => 'nullable|string|max:80', 'registry_volume' => 'nullable|string|max:80', 'started_at' => 'nullable|date_format:Y-m-d', 'legal_representative' => 'nullable|string|max:2000', 'city' => 'nullable|string|max:255', 'state' => 'nullable|string|max:255'], ['whatsapp.regex' => 'Escribe un WhatsApp válido, por ejemplo +58 412 1234567 o 0412-1234567.']);
        $v['document'] = array_key_exists('document', $v) ? ($v['document'] ?? '') : ($client->document ?? '');
        $v['phone'] = array_key_exists('phone', $v) ? ($v['phone'] ?? '') : ($client->phone ?? '');
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
                throw ValidationException::withMessages(['user_id' => 'El responsable debe pertenecer a la misma empresa administradora.']);
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

        return redirect()->route('clients.show', $client)->with('success', 'Concesionario guardado.');
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
