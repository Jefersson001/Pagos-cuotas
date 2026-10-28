<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Product;
use App\Services\Audit;
use App\Services\DealershipContext;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $r): View
    {
        $r->validate(['q' => 'nullable|string|max:120', 'active' => 'nullable|in:0,1']);
        $query = Product::visibleTo($r->user())->with('dealership')->withCount('items');
        if ($r->filled('q')) {
            $query->where('name', 'like', '%'.$r->input('q').'%');
        }
        if ($r->filled('active')) {
            $query->where('active', $r->input('active'));
        }

        return view('products.index', ['products' => $query->orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('products.form', ['product' => new Product(['active' => true, 'installment_count' => 5])]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.form', compact('product'));
    }

    public function store(Request $r): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        return $this->save($r, new Product);
    }

    public function update(Request $r, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        return $this->save($r, $product);
    }

    private function save(Request $r, Product $product): RedirectResponse
    {
        $v = $r->validate([
            'brand' => 'required|string|max:60',
            'model' => 'required|string|max:80',
            'transmission' => ['required', Rule::in(array_keys(Product::TRANSMISSIONS))],
            'year' => 'required|integer|min:1900|max:'.(today()->year + 1),
            'color' => ['required', Rule::in(array_keys(Product::COLORS))],
            'cash_price' => 'required|regex:/^\d{1,10}(\.\d{1,2})?$/',
            'price' => 'required|regex:/^\d{1,10}(\.\d{1,2})?$/',
            'installment_count' => 'required|integer|min:1|max:120',
            'initial_amount' => 'required|regex:/^\d{1,10}(\.\d{1,2})?$/',
            'active' => 'required|boolean',
            'dealership_id' => 'nullable|integer|exists:dealerships,id',
        ]);
        if (Money::cents($v['cash_price']) <= 0) {
            throw ValidationException::withMessages(['cash_price' => 'El precio contado debe ser mayor a cero.']);
        }
        if (Money::cents($v['price']) <= 0 || Money::cents($v['initial_amount']) > Money::cents($v['price'])) {
            throw ValidationException::withMessages(['initial_amount' => 'El precio debe ser mayor a cero y la inicial no puede superar el precio.']);
        }
        DB::transaction(function () use ($r, $product, $v) {
            if ($product->exists) {
                $product->setRawAttributes(Product::lockForUpdate()->findOrFail($product->id)->getAttributes(), true);
                Gate::authorize('update', $product);
            }
            $dealer = DealershipContext::forWrite($r->user(), $r->user()->isAdmin() ? ($v['dealership_id'] ?? null) : null, $product->exists ? $product->dealership_id : null);
            $before = $product->getAttributes();
            $product->fill(collect($v)->except('dealership_id')->all());
            $product->dealership_id = $dealer;
            if (! $product->exists) {
                $product->user_id = $r->user()->id;
            }
            $product->save();
            Audit::log($r->user()->id, 'save', 'product', $product->id, ['before' => $before, 'after' => $product->getAttributes()]);
        }, 3);

        return redirect()->route('products.edit', $product)->with('success', 'Producto guardado.');
    }

    public function destroy(Request $r, Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);
        DB::transaction(function () use ($r, $product) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            Gate::authorize('delete', $product);
            DealershipContext::forWrite($r->user(), null, $product->dealership_id);
            if ($product->items()->exists()) {
                throw ValidationException::withMessages(['product' => 'Este producto tiene ventas registradas. Puedes desactivarlo para conservar el historial.']);
            }
            $before = $product->getAttributes();
            $product->delete();
            Audit::log($r->user()->id, 'delete', 'product', $product->id, $before, $product->dealership_id);
        }, 3);

        return redirect()->route('products.index')->with('success', 'Producto eliminado.');
    }

    public function options(Request $r): JsonResponse
    {
        $r->validate(['client_id' => 'required|integer', 'q' => 'nullable|string|max:120']);
        $client = Client::findOrFail($r->input('client_id'));
        Gate::authorize('view', $client);
        $query = Product::where('dealership_id', $client->dealership_id)->where('active', true)->whereHas('dealership', fn ($q) => $q->where('active', true));
        if ($r->filled('q')) {
            $query->where('name', 'like', '%'.$r->input('q').'%');
        }
        $products = $query->orderBy('name')->orderBy('id')->limit(16)->get(['id', 'name', 'price', 'initial_amount']);

        return response()->json(['products' => $products->take(15), 'has_more' => $products->count() > 15])->header('Cache-Control', 'private, no-store');
    }
}
