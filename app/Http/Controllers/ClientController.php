<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Audit;
use App\Services\CustomerOverviewService;
use App\Services\DealershipContext;
use App\Services\HeadOfficeOverviewService;
use App\Services\ReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function directory(Request $request, CustomerOverviewService $overview): View
    {
        abort_unless($request->user()->isHeadOffice(), 403);
        $values = $request->validate([
            'q' => 'nullable|string|max:255',
            'active' => 'nullable|in:0,1',
            'account_state' => 'nullable|in:debt,overdue,credit,due_soon,received,sales',
            'concessionaire_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('customer_kind', 'dealership')->where('dealership_id', $request->user()->dealership_id)],
        ]);
        $query = Client::headOfficeCustomers($request->user())->with(['owner', 'concessionaire']);
        if ($request->filled('q')) {
            $term = '%'.$values['q'].'%';
            $query->where(fn (Builder $clients) => $clients->where('name', 'like', $term)->orWhere('document', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term)->orWhere('city', 'like', $term));
        }
        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if ($request->filled('concessionaire_id')) {
            $query->where('concessionaire_id', $request->integer('concessionaire_id'));
        }
        if ($request->filled('account_state')) {
            $group = $overview->build($request)['groups']['person'];
            $query->whereIn('id', $group['account_ids'][$values['account_state']]);
        }

        return view('clients.index', [
            'clients' => $query->latest()->paginate(15)->withQueryString(),
            'customerKind' => 'person',
            'readOnlyDirectory' => true,
            'concessionaires' => Client::where('dealership_id', $request->user()->dealership_id)->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function directoryShow(Request $request, int $client): View
    {
        $contact = Client::headOfficeCustomers($request->user())->with([
            'concessionaire', 'dealership',
            'orders' => fn (HasMany $orders) => $orders->where('dealership_id', $request->user()->dealership_id),
            'orders.payments.method', 'orders.installments.allocations.payment',
            'receipts' => fn (HasMany $receipts) => $receipts->where('dealership_id', $request->user()->dealership_id),
            'receipts.method', 'receipts.applications',
        ])->findOrFail($client);

        return view('clients.show', ['client' => $contact, 'readOnlyPortfolio' => true]);
    }

    public function directoryOrder(Request $request, int $order): View
    {
        $sale = Order::headOfficePortfolio($request->user())
            ->with(['client.concessionaire', 'client.receipts.applications', 'owner', 'items', 'payments.method', 'payments.recorder', 'installments.allocations.payment'])
            ->findOrFail($order);

        return view('orders.show', ['order' => $sale, 'methods' => collect(), 'readOnlyPortfolio' => true]);
    }

    public function directoryReceipt(Request $request, int $receipt, ReceiptService $service): View
    {
        abort_unless($request->user()->isHeadOffice(), 403);
        $payment = Receipt::visibleTo($request->user())->with(['client.concessionaire', 'method', 'recorder', 'approver', 'rejecter', 'voider', 'sourceOrder', 'intendedInstallment', 'applications.order', 'applications.recorder', 'applications.allocations.installment'])->findOrFail($receipt);

        return view('receipts.show', [
            'receipt' => $payment, 'orders' => collect(), 'editing' => false,
            'approvalPreview' => $service->approvalPreview($payment), 'duplicateReference' => false,
            'readOnlyPortfolio' => true,
        ]);
    }

    public function index(Request $r, CustomerOverviewService $overview): View
    {
        $r->validate(['q' => 'nullable|string|max:255', 'customer_kind' => 'nullable|in:person,dealership', 'business_type' => 'nullable|string|max:80', 'active' => 'nullable|in:0,1', 'account_state' => 'nullable|in:debt,overdue,credit,due_soon,received,sales', 'unassigned' => 'nullable|boolean']);
        $customerKind = $r->input('customer_kind', $r->route('customer_kind'));
        if ($r->user()->isHeadOffice()) {
            $customerKind = 'dealership';
        } elseif (! $r->user()->isAdmin() && $r->user()->concessionaire_id) {
            $customerKind = 'person';
        }
        $q = Client::visibleTo($r->user())->with(['owner', 'concessionaire']);
        if ($r->user()->isAdmin() && $r->boolean('unassigned')) {
            $q->where('customer_kind', 'person')->whereNull('concessionaire_id');
        }
        if ($customerKind) {
            $q->where('customer_kind', $customerKind);
        }
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
        if ($r->filled('account_state') || (($r->user()->isAdmin() || $r->user()->isHeadOffice()) && $r->filled('concessionaire_id'))) {
            $groups = $overview->build($r)['groups'];
            $state = $r->filled('account_state') ? $r->input('account_state') : 'all';
            $ids = collect($groups)->flatMap(fn (array $group): array => $group['account_ids'][$state])->all();
            $q->whereIn('id', $ids);
        }

        return view('clients.index', ['clients' => $q->latest()->paginate(15)->withQueryString(), 'customerKind' => $customerKind,
            'concessionaires' => $r->user()->isAdmin() || $r->user()->isHeadOffice() ? Client::visibleTo($r->user())->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name']) : collect()]);
    }

    public function create(Request $r)
    {
        $r->validate(['customer_kind' => 'nullable|in:person,dealership']);

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
        $users = $r->user()->canManageUsers() ? User::whereHas('role', fn ($role) => $role->whereIn('name', ['admin', 'dealership_admin', 'user']))->where('dealership_id', $selectedDealershipId)->where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        $requestedKind = $r->old('customer_kind', $client->customer_kind ?? $r->input('customer_kind', $r->route('customer_kind', 'person')));
        $customerKind = in_array($requestedKind, ['person', 'dealership'], true) ? $requestedKind : 'person';
        if ($r->user()->isHeadOffice()) {
            $customerKind = 'dealership';
        } elseif (! $r->user()->isAdmin() && $r->user()->concessionaire_id) {
            $customerKind = 'person';
        }
        $selectedConcessionaireId = $client->concessionaire_id ?? (! $r->user()->isAdmin() ? $r->user()->concessionaire_id : null);
        $concessionaires = $this->concessionairesFor($r->user(), $client->concessionaire_id)
            ->when($client->exists, fn (Builder $query) => $query->where('id', '!=', $client->id))
            ->where(fn (Builder $query) => $query->where('active', true)->orWhereKey($selectedConcessionaireId))
            ->with('dealership:id,name')->orderBy('name')->get();

        $organizations = $r->user()->isAdmin() ? Dealership::where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        return compact('client', 'users', 'selectedDealershipId', 'customerKind', 'concessionaires', 'selectedConcessionaireId', 'organizations');
    }

    /** @return Builder<Client> */
    private function concessionairesFor(User $actor, ?int $existingId = null): Builder
    {
        $query = Client::where('customer_kind', 'dealership');
        if (! $actor->isAdmin()) {
            $query->where('dealership_id', $actor->dealership_id);
            if ($actor->concessionaire_id) {
                $query->whereKey($actor->concessionaire_id);
            }
            if (! $actor->canManageBusiness()) {
                $query->where(fn (Builder $query) => $query->where('user_id', $actor->id)
                    ->orWhereIn('id', array_filter([$actor->concessionaire_id, $existingId])));
            }
        }

        return $query;
    }

    public function responsibleUsers(Request $r): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        $values = $r->validate(['dealership_id' => 'required|integer|exists:dealerships,id']);
        $id = (int) $values['dealership_id'];
        abort_unless($r->user()->isAdmin() || $r->user()->dealership_id === $id, 403);
        Dealership::where('active', true)->findOrFail($id);

        return response()->json(['users' => User::whereHas('role', fn ($role) => $role->whereIn('name', ['admin', 'dealership_admin', 'user']))->where('dealership_id', $id)->where('active', true)->orderBy('name')->get(['id', 'name'])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Client $client): View
    {
        Gate::authorize('view', $client);
        if ($request->user()->isHeadOffice()) {
            $headOfficeResults = app(HeadOfficeOverviewService::class)->build($request, null, null, $client->id)['rows']->firstWhere('id', $client->id);

            return view('clients.show', compact('client', 'headOfficeResults'));
        }
        $client->load(['concessionaire', 'receipts.method', 'receipts.applications', 'orders.payments.method', 'orders.installments.allocations.payment']);

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
        if ($r->user()->isHeadOffice()) {
            if ($r->input('customer_kind', $client->customer_kind ?? 'dealership') !== 'dealership') {
                throw ValidationException::withMessages(['customer_kind' => 'Casa matriz solo puede registrar concesionarios, no clientes finales.']);
            }
            abort_if(($r->filled('dealership_id') && $r->integer('dealership_id') !== $r->user()->dealership_id) || $r->filled('concessionaire_id'), 403);
            $r->merge(['customer_kind' => 'dealership', 'dealership_id' => $r->user()->dealership_id]);
        }
        if (! $r->user()->isAdmin() && $r->user()->concessionaire_id) {
            if ($r->input('customer_kind', $client->customer_kind ?? 'person') !== 'person') {
                throw ValidationException::withMessages(['customer_kind' => 'Un concesionario solo puede registrar clientes de su propia cartera.']);
            }
        }
        $r->validate(['user_id' => 'sometimes|required|integer', 'concessionaire_id' => 'nullable|integer|exists:clients,id']);
        $concessionaire = null;
        if ($r->input('customer_kind', $client->customer_kind ?? 'person') === 'person') {
            $concessionaireId = $r->input('concessionaire_id', $client->concessionaire_id);
            if (! $r->user()->isAdmin() && $r->user()->concessionaire_id) {
                $assignedId = $client->concessionaire_id ?? $r->user()->concessionaire_id;
                if ($r->filled('concessionaire_id') && $r->integer('concessionaire_id') !== $assignedId) {
                    throw ValidationException::withMessages(['concessionaire_id' => 'El concesionario se asigna automáticamente según tu usuario.']);
                }
                $concessionaireId = $assignedId;
            }
            if ($concessionaireId) {
                $concessionaire = $this->concessionairesFor($r->user(), $client->concessionaire_id)->find($concessionaireId);
                if (! $concessionaire || (! $concessionaire->active && $client->concessionaire_id !== $concessionaire->id)) {
                    throw ValidationException::withMessages(['concessionaire_id' => 'Selecciona un concesionario activo de Contactos.']);
                }
            }
        }
        $companyId = $client->dealership_id ?? $concessionaire?->dealership_id ?? ($r->user()->isAdmin() && $r->filled('dealership_id') ? $r->integer('dealership_id') : null) ?? DealershipContext::id($r->user()) ?? Dealership::where('active', true)->orderBy('id')->value('id');
        $defaultOwner = $client->user_id ?? ($r->user()->isAdmin()
            ? (User::whereHas('role', fn ($role) => $role->whereIn('name', ['admin', 'dealership_admin', 'user']))->where('dealership_id', $companyId)->where('active', true)->whereHas('role', fn ($q) => $q->where('name', 'user'))->orderBy('id')->value('id') ?? $r->user()->id)
            : $r->user()->id);
        $owner = $r->user()->isAdmin() ? (int) $r->input('user_id', $defaultOwner) : ($client->user_id ?? $r->user()->id);
        $keepsAssignedOwner = $client->exists && (($r->user()->concessionaire_id && $client->concessionaire_id === $r->user()->concessionaire_id) || ($r->user()->isHeadOffice() && $client->customer_kind === 'dealership' && $client->dealership_id === $r->user()->dealership_id));
        if ($r->user()->isHeadOffice() && $r->filled('user_id')) {
            abort_unless($r->integer('user_id') === $owner, 403);
        }
        if ($r->user()->managesDealership() && $r->filled('user_id')) {
            abort_unless($r->integer('user_id') === $owner, 403);
        }
        if ($r->user()->managesDealership() && ! $keepsAssignedOwner) {
            abort_unless(User::managedBy($r->user())->whereKey($owner)->exists(), 403);
        }
        if ($r->has('whatsapp')) {
            $r->merge(['whatsapp' => $this->normalizeWhatsApp($r->input('whatsapp'))]);
        }
        if (! $r->has('active')) {
            $condition = mb_strtoupper(trim((string) $r->input('condition')));
            $r->merge(['active' => $condition === '' ? ($client->active ?? true) : ! in_array($condition, ['INACTIVO', 'INACTIVA'], true)]);
        }
        $v = $r->validate(['customer_kind' => 'sometimes|required|in:person,dealership', 'name' => 'required|string|max:255', 'document' => 'required_if:duplicate_record,client|nullable|string|max:80', 'phone' => 'nullable|string|max:255', 'whatsapp' => 'nullable|string|regex:/^[0-9]{7,20}$/', 'email' => 'nullable|string|max:255', 'address' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:3000', 'active' => 'required|boolean', 'dealership_id' => 'nullable|integer|exists:dealerships,id', 'business_type' => 'nullable|string|max:80', 'condition' => 'nullable|string|max:80', 'list_number' => 'nullable|integer|min:1', 'refrigerators' => 'nullable|integer|min:0', 'refrigerators_assigned_at' => 'nullable|date_format:Y-m-d', 'listed_started_at' => 'nullable|date_format:Y-m-d', 'commercial_registry' => 'nullable|string|max:2000', 'registry_jurisdiction' => 'nullable|string|max:255', 'registry_date' => 'nullable|date_format:Y-m-d', 'registry_number' => 'nullable|string|max:80', 'registry_volume' => 'nullable|string|max:80', 'started_at' => 'nullable|date_format:Y-m-d', 'legal_representative' => 'nullable|string|max:2000', 'city' => 'nullable|string|max:255', 'state' => 'nullable|string|max:255'], ['whatsapp.regex' => 'Escribe un WhatsApp válido, por ejemplo +58 412 1234567 o 0412-1234567.']);
        $v['customer_kind'] = $v['customer_kind'] ?? ($client->customer_kind ?? 'person');
        $v['document'] = array_key_exists('document', $v) ? ($v['document'] ?? '') : ($client->document ?? '');
        $v['phone'] = array_key_exists('phone', $v) ? ($v['phone'] ?? '') : ($client->phone ?? '');
        $v['concessionaire_id'] = $concessionaire?->id;
        DB::transaction(function () use ($v, $owner, $client, $r, $concessionaire, $keepsAssignedOwner) {
            if ($client->exists) {
                $locked = Client::lockForUpdate()->findOrFail($client->id);
                Gate::forUser($r->user())->authorize('update', $locked);
                $client->setRawAttributes($locked->getAttributes(), true);
                if ($client->orders()->exists() || $client->receipts()->exists()) {
                    if ($client->customer_kind !== $v['customer_kind']) {
                        throw ValidationException::withMessages(['customer_kind' => 'Este contacto tiene operaciones. Conserva su tipo para proteger el historial.']);
                    }
                    if ($client->concessionaire_id !== $v['concessionaire_id']) {
                        throw ValidationException::withMessages(['concessionaire_id' => 'Este cliente tiene operaciones. Su concesionario debe conservarse para no trasladar deudas y pagos históricos.']);
                    }
                }
                if ($client->customer_kind === 'dealership' && $v['customer_kind'] !== 'dealership'
                    && ($client->customers()->exists() || User::where('concessionaire_id', $client->id)->exists())) {
                    throw ValidationException::withMessages(['customer_kind' => 'Este concesionario tiene clientes o usuarios relacionados. Conserva su tipo de contacto.']);
                }
                if ($client->customer_kind === 'person' && $v['customer_kind'] !== 'person' && User::where('client_id', $client->id)->exists()) {
                    throw ValidationException::withMessages(['customer_kind' => 'Este cliente tiene un acceso personal relacionado. Conserva su tipo de contacto.']);
                }
            }
            $responsible = User::lockForUpdate()->findOrFail($owner);
            if (! $responsible->active || (! $responsible->canOperateFinancial() && ! ($r->user()->isHeadOffice() && $responsible->isHeadOffice() && $responsible->dealership_id === $r->user()->dealership_id))) {
                throw ValidationException::withMessages(['user_id' => 'El responsable está inactivo.']);
            }
            $requested = $r->user()->isAdmin() ? ($concessionaire?->dealership_id ?? $v['dealership_id'] ?? $responsible->dealership_id) : null;
            $dealerId = DealershipContext::forContactWrite($r->user(), $requested ? (int) $requested : null, $client->exists ? $client->dealership_id : null);
            if ($concessionaire) {
                $linked = $this->concessionairesFor($r->user(), $client->concessionaire_id)->lockForUpdate()->find($concessionaire->id);
                if (! $linked || $linked->id === $client->id || $linked->dealership_id !== $dealerId
                    || (! $linked->active && $client->concessionaire_id !== $linked->id)) {
                    throw ValidationException::withMessages(['concessionaire_id' => 'El concesionario debe estar activo y pertenecer a la misma empresa del cliente.']);
                }
            }
            if (! $r->user()->isAdmin()) {
                abort_unless(($responsible->dealership_id === $dealerId && ! $responsible->isAdmin()) || ($keepsAssignedOwner && $responsible->isAdmin()), 403);
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

        return redirect()->route('clients.show', $client)->with('success', $client->customer_kind === 'dealership' ? 'Concesionario guardado.' : 'Cliente guardado.');
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
