<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Services\Audit;
use App\Services\DealershipContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentMethodController extends Controller
{
    private function authorizeAdmin(Request $request, ?PaymentMethod $method = null): void
    {
        abort_unless($request->user()->canManageUsers(), 403);
        if ($method && ! $request->user()->isAdmin()) {
            abort_unless($method->dealership_id === $request->user()->dealership_id, 403);
        }
    }

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);
        $request->validate(['q' => 'nullable|string|max:80', 'active' => 'nullable|in:0,1']);
        $query = PaymentMethod::visibleTo($request->user())->with('dealership')->withCount(['receipts as payments_count']);
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->input('q').'%');
        }
        if ($request->filled('active')) {
            $query->where('active', $request->input('active'));
        }

        return view('admin.payment-methods.index', ['methods' => $query->orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.payment-methods.form', ['method' => new PaymentMethod(['active' => true])]);
    }

    public function edit(Request $request, PaymentMethod $paymentMethod): View
    {
        $this->authorizeAdmin($request, $paymentMethod);

        return view('admin.payment-methods.form', ['method' => $paymentMethod]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        return $this->save($request, new PaymentMethod);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorizeAdmin($request, $paymentMethod);

        return $this->save($request, $paymentMethod);
    }

    private function save(Request $request, PaymentMethod $method): RedirectResponse
    {
        $dealerId = DealershipContext::forWrite($request->user(), $request->filled('dealership_id') ? $request->integer('dealership_id') : null, $method->exists ? $method->dealership_id : null);
        $values = $request->validate(['dealership_id' => 'nullable|integer|exists:dealerships,id', 'name' => ['required', 'string', 'max:80', Rule::unique('payment_methods')->where('dealership_id', $dealerId)->ignore($method->id)], 'active' => 'required|boolean']);
        $values['dealership_id'] = $dealerId;
        DB::transaction(function () use ($request, $method, $values) {
            if ($method->exists) {
                $method->setRawAttributes(PaymentMethod::lockForUpdate()->findOrFail($method->id)->getAttributes(), true);
            }
            $before = $method->getAttributes();
            $method->fill($values)->save();
            Audit::log($request->user()->id, 'save', 'payment_method', $method->id, ['before' => $before, 'after' => $method->getAttributes()]);
        });

        return redirect()->route('payment-methods.edit', $method)->with('success', 'Método de pago guardado.');
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorizeAdmin($request, $paymentMethod);
        DB::transaction(function () use ($request, $paymentMethod) {
            $method = PaymentMethod::lockForUpdate()->findOrFail($paymentMethod->id);
            $this->authorizeAdmin($request, $method);
            if ($method->payments()->exists() || $method->receipts()->exists()) {
                throw ValidationException::withMessages(['method' => 'Este método tiene pagos registrados y no se puede eliminar. Puedes desactivarlo desde su formulario.']);
            }
            $before = $method->getAttributes();
            $method->delete();
            Audit::log($request->user()->id, 'delete', 'payment_method', $method->id, $before, $method->dealership_id);
        });

        return redirect()->route('payment-methods.index')->with('success', 'Método de pago eliminado.');
    }
}
