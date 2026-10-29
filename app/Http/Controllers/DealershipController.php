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

class DealershipController extends Controller
{
    private function check(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    public function index(Request $request): RedirectResponse
    {
        $this->check($request);

        return redirect()->route('clients.index');
    }

    public function create(Request $request): RedirectResponse
    {
        $this->check($request);

        return redirect()->route('clients.create');
    }

    public function edit(Request $request, Dealership $dealership): RedirectResponse
    {
        $this->check($request);

        return redirect()->route('clients.index');
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
        $values = $request->validate(['name' => 'required|string|max:255', 'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('dealerships')->ignore($dealership->id)], 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:2000', 'active' => 'required|boolean']);
        DB::transaction(function () use ($request, $dealership, $values) {
            $new = ! $dealership->exists;
            if (! $new) {
                $dealership->setRawAttributes(Dealership::lockForUpdate()->findOrFail($dealership->id)->getAttributes(), true);
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

        return redirect()->route('dealerships.edit', $dealership)->with('success', 'Concesionario guardado.');
    }

    public function context(Request $request): RedirectResponse|JsonResponse
    {
        $this->check($request);
        $values = $request->validate(['dealership_id' => 'nullable|integer|exists:dealerships,id']);
        if (! empty($values['dealership_id'])) {
            $request->session()->put('dealership_id', (int) $values['dealership_id']);
        } else {
            $request->session()->forget('dealership_id');
        }

        if ($request->expectsJson()) {
            return response()->json(['dealership_id' => $request->session()->get('dealership_id')]);
        }

        return redirect()->route('dashboard');
    }
}
