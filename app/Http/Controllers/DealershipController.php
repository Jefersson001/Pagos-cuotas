<?php

namespace App\Http\Controllers;

use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DealershipController extends Controller
{
    private function check(Request $request, ?Dealership $dealership = null): void
    {
        abort_unless($request->user()->canManageOrganizations(), 403);
        if ($dealership && ! $request->user()->isAdmin()) {
            abort_unless($dealership->parent_id === $request->user()->dealership_id && $dealership->organization_type === 'concessionaire', 403);
        }
    }

    public function index(Request $request): View
    {
        $this->check($request);

        return view('admin.dealerships.index', ['rows' => Dealership::with('parent')->when(! $request->user()->isAdmin(), fn ($query) => $query->where('parent_id', $request->user()->dealership_id)->where('organization_type', 'concessionaire'))->withCount(['users', 'clients', 'orders'])->orderBy('name')->paginate(20)]);
    }

    public function create(Request $request): View
    {
        $this->check($request);

        return view('admin.dealerships.form', ['dealership' => new Dealership(['active' => true, 'organization_type' => $request->user()->isAdmin() ? 'head_office' : 'concessionaire'])]);
    }

    public function show(Request $request, Dealership $dealership): View
    {
        $this->check($request, $dealership);

        request()->session()->forget('_old_input');

        return view('admin.dealerships.form', ['dealership' => $dealership, 'readOnly' => true]);
    }

    public function edit(Request $request, Dealership $dealership): View
    {
        $this->check($request, $dealership);

        return view('admin.dealerships.form', ['dealership' => $dealership]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request);

        return $this->save($request, new Dealership);
    }

    public function update(Request $request, Dealership $dealership): RedirectResponse
    {
        $this->check($request);

        return $this->save($request, $dealership);
    }

    private function save(Request $request, Dealership $dealership): RedirectResponse
    {
        $this->check($request, $dealership->exists ? $dealership : null);
        if (! $request->user()->isAdmin()) {
            abort_if($request->input('organization_type', 'concessionaire') !== 'concessionaire', 403);
            $request->merge(['organization_type' => 'concessionaire', 'parent_id' => $request->user()->dealership_id]);
        }
        $request->mergeIfMissing(['organization_type' => $dealership->organization_type ?? ($request->user()->isAdmin() ? 'head_office' : 'concessionaire'), 'parent_id' => $dealership->exists ? $dealership->parent_id : null]);
        $values = $request->validate(['name' => 'required|string|max:255', 'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('dealerships')->ignore($dealership->id)], 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:2000', 'active' => 'required|boolean', 'organization_type' => 'required|in:head_office,concessionaire', 'parent_id' => 'nullable|integer|exists:dealerships,id']);
        $keepsUnassignedParent = $dealership->exists && $dealership->organization_type === 'concessionaire' && $dealership->parent_id === null && $values['parent_id'] === null;
        if ($values['organization_type'] === 'head_office') {
            $values['parent_id'] = null;
        } elseif (! $keepsUnassignedParent && ! Dealership::whereKey($values['parent_id'])->where('organization_type', 'head_office')->where('id', '!=', $dealership->id ?? 0)->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Selecciona la casa matriz de este concesionario.']);
        }
        DB::transaction(function () use ($request, $dealership, $values) {
            $new = ! $dealership->exists;
            if (! $new) {
                $dealership->setRawAttributes(Dealership::lockForUpdate()->findOrFail($dealership->id)->getAttributes(), true);
                $this->check($request, $dealership);
            }
            if ($dealership->exists && $dealership->organization_type !== $values['organization_type'] && ($dealership->users()->exists() || $dealership->clients()->exists() || $dealership->orders()->exists() || $dealership->branches()->exists())) {
                throw ValidationException::withMessages(['organization_type' => 'Esta organización ya tiene relaciones. Conserva su tipo para mantener la jerarquía.']);
            }
            $before = $dealership->getAttributes();
            $dealership->fill($values)->save();
            if ($new) {
                foreach (['Efectivo', 'Transferencia', 'Pago Móvil', 'Zelle', 'Divisas', 'Otro'] as $name) {
                    PaymentMethod::create(['dealership_id' => $dealership->id, 'name' => $name, 'active' => true]);
                }
            }
            Audit::log($request->user()->id, 'save', 'dealership', $dealership->id, ['before' => $before, 'after' => $dealership->getAttributes()], $dealership->id);
        });

        return redirect()->route('dealerships.edit', $dealership)->with('success', 'Organización guardada.');
    }

    public function context(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $values = $request->validate(['dealership_id' => 'nullable|integer|exists:dealerships,id']);
        if (! empty($values['dealership_id'])) {
            $request->session()->put('dealership_id', (int) $values['dealership_id']);
        } else {
            $request->session()->forget('dealership_id');
        }

        if ($request->expectsJson()) {
            return response()->json(['dealership_id' => $request->session()->get('dealership_id')]);
        }

        return redirect()->route('workspace.sales');
    }
}
