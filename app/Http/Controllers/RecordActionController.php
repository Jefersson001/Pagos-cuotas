<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Audit;
use App\Services\ReceiptService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordActionController extends Controller
{
    private function record(Request $request, string $type, int $id, bool $lock = false): Model
    {
        $class = match ($type) {
            'client' => Client::class, 'product' => Product::class, 'user' => User::class,
            'company' => Dealership::class, 'method' => PaymentMethod::class,
            'order' => Order::class, 'receipt' => Receipt::class, default => abort(404),
        };
        $record = $class::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        $actor = $request->user();
        if ($type === 'user') {
            abort_unless($actor->isAdmin(), 403);
        } elseif ($type === 'company') {
            abort_unless($actor->canManageOrganizations() && ($actor->isAdmin() || ($record->parent_id === $actor->dealership_id && $record->organization_type === 'concessionaire')), 403);
        } elseif ($type === 'method') {
            abort_unless($actor->canManageBusiness() && ($actor->isAdmin() || $record->dealership_id === $actor->dealership_id), 403);
        } else {
            Gate::forUser($actor)->authorize('update', $record);
        }

        return $record;
    }

    public function duplicate(Request $request, string $type, int $id): RedirectResponse
    {
        $record = $this->record($request, $type, $id);
        $fields = match ($type) {
            'client' => ['customer_kind', 'name', 'phone', 'email', 'address', 'city', 'state', 'whatsapp', 'notes', 'active', 'dealership_id', 'concessionaire_id', 'business_type', 'condition', 'started_at', 'legal_representative'],
            'product' => ['product_type', 'name', 'description', 'brand', 'model', 'transmission', 'year', 'color', 'cash_price', 'price', 'initial_amount', 'installment_count', 'pay_in_installments', 'active', 'dealership_id'],
            'user' => ['name', 'role_id', 'dealership_id'],
            'company' => ['name', 'organization_type', 'parent_id', 'phone', 'address', 'active'],
            'method' => ['name', 'active', 'dealership_id'],
            'order' => ['client_id', 'frequency', 'notes'],
            'receipt' => ['client_id', 'amount', 'payment_method_id', 'notes'],
        };
        $values = $record->only($fields);
        if (isset($values['name'])) {
            $values['name'] = Str::limit($values['name'], $type === 'method' ? 70 : 165, '').' (copia)';
        }
        $route = match ($type) {
            'client' => 'clients.create',
            'product' => 'products.create', 'user' => 'users.create', 'company' => 'dealerships.create',
            'method' => 'payment-methods.create', 'order' => 'orders.create', 'receipt' => 'receipts.create',
        };
        if ($type === 'user') {
            $values['active'] = 0;
        }
        if ($type === 'product') {
            $values['duplicate_product_id'] = $record->id;
        }
        if ($type === 'client') {
            $values['document'] = '';
            $values['duplicate_record'] = 'client';
        }
        if ($type === 'order') {
            $values['items'] = $record->items->filter(fn ($item) => $item->product_id)->map(fn ($item): array => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->values()->all();
            $values['installment_count'] = max(1, $record->installments->where('number', '>', 0)->count());
            $values['frequency'] = $record->frequency === 'custom' ? 'monthly' : $record->frequency;
        }

        return redirect()->route($route)->withInput($values)->with('success', 'Borrador preparado. Revisa los datos y guarda para crear un registro nuevo.');
    }

    public function destroy(Request $request, string $type, int $id, ReceiptService $receipts): RedirectResponse
    {
        $record = $this->record($request, $type, $id);
        if ($type === 'product') {
            return app(ProductController::class)->destroy($request, $record);
        }
        if ($type === 'method') {
            return app(PaymentMethodController::class)->destroy($request, $record);
        }
        if ($type === 'receipt') {
            $receipts->void($request->user(), $record, 'Eliminación solicitada desde la ficha del pago.');

            return redirect()->route('receipts.show', $record)->with('success', 'Ingreso anulado. Se conserva su historial y se actualizaron las deudas y los saldos.');
        }
        $route = DB::transaction(function () use ($request, $type, $id): string {
            if ($type === 'user') {
                User::whereHas('role', fn ($role) => $role->where('name', 'admin'))->where('active', true)->orderBy('id')->lockForUpdate()->get();
            }
            $record = $this->record($request, $type, $id, true);
            $blocked = match ($type) {
                'client' => $record->orders()->exists() || $record->receipts()->exists() || $record->customers()->exists() || User::where('client_id', $id)->orWhere('concessionaire_id', $id)->exists(),
                'user' => $id === $request->user()->id || Client::where('user_id', $id)->exists() || Order::where('user_id', $id)->exists() || Receipt::where('recorded_by', $id)->orWhere('user_id', $id)->exists() || Payment::where('recorded_by', $id)->orWhere('user_id', $id)->orWhere('voided_by', $id)->exists() || Product::where('user_id', $id)->exists() || DB::table('audit_logs')->where('user_id', $id)->exists(),
                'company' => $record->branches()->exists() || $record->users()->exists() || $record->clients()->exists() || $record->orders()->exists() || Product::where('dealership_id', $id)->exists() || Receipt::where('dealership_id', $id)->exists() || Payment::where('dealership_id', $id)->exists(),
                'order' => $record->payments()->exists() || Receipt::where('source_order_id', $id)->exists(),
                default => true,
            };
            if ($type === 'user' && $record->isAdmin() && $record->active && User::whereHas('role', fn ($role) => $role->where('name', 'admin'))->where('active', true)->count() <= 1) {
                $blocked = true;
            }
            if ($blocked) {
                throw ValidationException::withMessages(['record' => 'Este registro tiene relaciones o historial que deben conservarse. Desactívalo o utiliza la anulación correspondiente.']);
            }
            $before = $type === 'user' ? $record->only(['name', 'email', 'role_id', 'dealership_id']) : $record->getAttributes();
            if ($type === 'company') {
                PaymentMethod::where('dealership_id', $id)->delete();
                DB::table('dealership_settings')->where('dealership_id', $id)->delete();
            }
            if ($type === 'order') {
                $record->installments()->delete();
                $record->items()->delete();
            }
            $companyId = $type === 'company' ? $id : $record->dealership_id;
            $record->delete();
            Audit::log($request->user()->id, 'delete', match ($type) {
                'company' => 'dealership', default => $type
            }, $id, ['before' => $before], $companyId);

            return match ($type) {
                'client' => 'clients.index',
                'user' => 'users', 'company' => 'dealerships.index', 'order' => 'orders.index',
            };
        }, 3);

        return redirect()->route($route)->with('success', 'Registro eliminado.');
    }
}
