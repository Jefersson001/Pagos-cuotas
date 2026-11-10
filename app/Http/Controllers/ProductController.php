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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $r): View
    {
        $r->validate(['q' => 'nullable|string|max:120', 'active' => 'nullable|in:0,1']);
        $query = Product::visibleTo($r->user())->with('dealership');
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term)->orWhere('brand', 'like', $term)->orWhere('model', 'like', $term));
        }
        if ($r->filled('active')) {
            $query->where('active', $r->input('active'));
        }

        return view('products.index', ['products' => $query->orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Product::class);

        $product = new Product(['active' => true, 'product_type' => 'general', 'installment_count' => 5]);
        if ($request->old('duplicate_product_id')) {
            $source = Product::findOrFail($request->old('duplicate_product_id'));
            Gate::authorize('update', $source);
            $product->image_path = $source->image_path;
        }

        return view('products.form', compact('product'));
    }

    public function show(Product $product): View
    {
        Gate::authorize('view', $product);

        request()->session()->forget('_old_input');

        return view('products.form', ['product' => $product, 'readOnly' => true]);
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
        $r->mergeIfMissing(['product_type' => $r->filled('brand') && $r->filled('model') ? 'vehicle' : 'general', 'installment_count' => 5, 'initial_amount' => '0', 'active' => $product->active ?? true, 'pay_in_installments' => $product->pay_in_installments ?? true]);
        if (! $r->boolean('pay_in_installments')) {
            $r->merge(['cash_price' => $r->input('price'), 'initial_amount' => $r->input('price'), 'installment_count' => 1]);
        }
        $v = $r->validate([
            'duplicate_product_id' => 'nullable|integer|exists:products,id', 'product_type' => 'required|in:general,vehicle,service', 'name' => 'required_unless:product_type,vehicle|nullable|string|max:180',
            'sku' => 'nullable|string|max:80', 'description' => 'nullable|string|max:2000', 'pay_in_installments' => 'required|boolean',
            'brand' => 'required_if:product_type,vehicle|nullable|string|max:60', 'model' => 'required_if:product_type,vehicle|nullable|string|max:80',
            'transmission' => ['nullable', Rule::in(array_keys(Product::TRANSMISSIONS))],
            'year' => 'nullable|integer|min:1900|max:'.(today()->year + 1), 'color' => ['nullable', Rule::in(array_keys(Product::COLORS))],
            'cash_price' => 'nullable|regex:/^\d{1,10}(\.\d{1,2})?$/', 'price' => 'required|regex:/^\d{1,10}(\.\d{1,2})?$/',
            'initial_amount' => 'required|regex:/^\d{1,10}(\.\d{1,2})?$/', 'installment_count' => 'required|integer|min:1|max:120',
            'active' => 'required|boolean', 'dealership_id' => 'nullable|integer|exists:dealerships,id', 'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);
        $total = Money::cents($v['price']);
        if ($total <= 0 && ! $v['pay_in_installments']) {
            throw ValidationException::withMessages(['price' => 'El precio de venta debe ser mayor a cero.']);
        }
        $v['cash_price'] = $v['cash_price'] ?? $v['price'];
        if (Money::cents($v['cash_price']) <= 0) {
            throw ValidationException::withMessages(['cash_price' => 'El precio contado debe ser mayor a cero.']);
        }
        $initial = Money::cents($v['initial_amount']);
        if ($total <= 0 || $initial > $total) {
            throw ValidationException::withMessages(['initial_amount' => 'El precio debe ser mayor a cero y la inicial no puede superar el precio.']);
        }
        if ($total > $initial && $total - $initial < (int) $v['installment_count']) {
            throw ValidationException::withMessages(['installment_count' => 'El saldo es menor a un céntimo por cuota.']);
        }
        if ($v['product_type'] !== 'vehicle') {
            foreach (['brand', 'model', 'transmission', 'year', 'color'] as $field) {
                $v[$field] = null;
            }
        }
        $uploaded = null;
        try {
            DB::transaction(function () use ($r, $product, $v, &$uploaded) {
                if ($product->exists) {
                    $product->setRawAttributes(Product::lockForUpdate()->findOrFail($product->id)->getAttributes(), true);
                    Gate::authorize('update', $product);
                }
                $company = DealershipContext::forWrite($r->user(), $r->user()->isAdmin() ? ($v['dealership_id'] ?? null) : null, $product->exists ? $product->dealership_id : null);
                $before = $product->getAttributes();
                if (! $product->exists && ! empty($v['duplicate_product_id'])) {
                    $source = Product::findOrFail($v['duplicate_product_id']);
                    Gate::authorize('update', $source);
                    if ($source->dealership_id !== $company) {
                        throw ValidationException::withMessages(['duplicate_product_id' => 'La copia debe pertenecer a la misma organización.']);
                    }
                    $product->image_path = $source->image_path;
                }
                $values = collect($v)->except(['dealership_id', 'image', 'duplicate_product_id'])->all();
                if ($r->hasFile('image')) {
                    $uploaded = $r->file('image')->store('products', 'public');
                    $values['image_path'] = $uploaded;
                }
                $product->fill($values);
                $product->dealership_id = $company;
                if (! $product->exists) {
                    $product->user_id = $r->user()->id;
                }
                $product->save();
                Audit::log($r->user()->id, 'save', 'product', $product->id, ['before' => $before, 'after' => $product->getAttributes()]);
            }, 3);
        } catch (\Throwable $error) {
            if ($uploaded) {
                Storage::disk('public')->delete($uploaded);
            }throw $error;
        }

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
            }$before = $product->getAttributes();
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
            $term = '%'.$r->input('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term));
        }
        $products = $query->orderBy('name')->orderBy('id')->limit(16)->get(['id', 'name', 'price', 'initial_amount', 'installment_count', 'pay_in_installments']);

        return response()->json(['products' => $products->take(15)->map(fn (Product $product): array => [
            'id' => $product->id, 'name' => $product->name, 'price' => $product->price,
            'initial_amount' => $product->pay_in_installments ? $product->initial_amount : '0.00',
            'installment_count' => $product->pay_in_installments ? $product->installment_count : 1,
            'pay_in_installments' => $product->pay_in_installments, 'catalog_version' => $product->catalog_version,
        ]), 'has_more' => $products->count() > 15])->header('Cache-Control', 'private, no-store');
    }
}
