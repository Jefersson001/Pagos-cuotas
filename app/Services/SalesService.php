<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['amount' => $message]);
    }

    public function create(User $actor, array $data): Order
    {
        return DB::transaction(function () use ($actor, $data) {
            $client = Client::lockForUpdate()->findOrFail($data['client_id']);
            Gate::forUser($actor)->authorize('view', $client);
            DealershipContext::forWrite($actor, null, $client->dealership_id);
            if (! $client->active) {
                $this->fail('El concesionario está inactivo.');
            }
            if ($old = Order::where('token', $data['token'])->first()) {
                Gate::forUser($actor)->authorize('view', $old);

                return $old;
            }
            if (collect($data['items'])->contains(fn ($item) => isset($item['product_id']))) {
                $ids = array_column($data['items'], 'product_id');
                $products = Product::whereIn('id', $ids)->where('dealership_id', $client->dealership_id)->where('active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $catalogItems = [];
                $initialTotal = 0;
                foreach ($data['items'] as $item) {
                    $product = $products->get($item['product_id'] ?? null);
                    if (! $product) {
                        $this->fail('Selecciona productos activos del mismo concesionario que el concesionario.');
                    }
                    $initialTotal += Money::cents($product->initial_amount) * (int) $item['quantity'];
                    if ($initialTotal > 999999999999) {
                        $this->fail('La inicial total supera el importe permitido.');
                    }
                    $catalogItems[] = ['product_id' => $product->id, 'description' => $product->name, 'quantity' => $item['quantity'], 'unit_price' => $product->price, 'initial_amount' => $product->initial_amount];
                }
                $data['items'] = $catalogItems;
                $data['initial_amount'] = Money::decimal($initialTotal);
                $data['description'] = Str::limit(implode(', ', array_column($catalogItems, 'description')), 255, '');
            }
            $total = 0;
            $items = [];
            foreach ($data['items'] as $item) {
                $price = Money::cents($item['unit_price']);
                $total += $price * (int) $item['quantity'];
                if ($total > 999999999999) {
                    $this->fail('El total de la venta supera el importe permitido.');
                }
                $items[] = ['product_id' => $item['product_id'] ?? null, 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price' => Money::decimal($price), 'initial_amount' => $item['initial_amount'] ?? '0'];
            }
            if ($total <= 0 || $total > 999999999999) {
                $this->fail('El total de la venta no es válido.');
            }
            $initial = Money::cents($data['initial_amount'] ?? '0');
            if ($initial > $total) {
                $this->fail('La inicial supera el total.');
            }
            $balance = $total - $initial;
            $count = (int) $data['installment_count'];
            $schedule = [];
            if ($balance > 0) {
                if ($balance < $count) {
                    $this->fail('El saldo es menor a un céntimo por cuota.');
                }
                $start = Carbon::parse($data['first_due']);
                for ($n = 0; $n < $count; $n++) {
                    if ($data['frequency'] === 'custom') {
                        $row = $data['custom'][$n] ?? null;
                        if (! $row) {
                            $this->fail('Complete todas las cuotas personalizadas.');
                        }
                        $amount = Money::cents($row['amount']);
                        $date = Carbon::parse($row['due_date'])->toDateString();
                    } else {
                        $amount = intdiv($balance, $count) + ($n < $balance % $count ? 1 : 0);
                        $date = match ($data['frequency']) {
                            'weekly' => $start->copy()->addWeeks($n),'fortnightly' => $start->copy()->addDays(15 * $n),default => $start->copy()->addMonthsNoOverflow($n)
                        };
                        $date = $date->toDateString();
                    }
                    if ($amount <= 0) {
                        $this->fail('Cada cuota debe ser mayor a cero.');
                    }
                    $schedule[] = ['number' => $n + 1, 'due_date' => $date, 'amount' => Money::decimal($amount)];
                }
                if (array_sum(array_map(fn ($i) => Money::cents($i['amount']), $schedule)) !== $balance) {
                    $this->fail('La suma de las cuotas debe coincidir exactamente con el saldo financiado.');
                }
            }
            $order = Order::create(['token' => $data['token'], 'user_id' => $client->user_id, 'dealership_id' => $client->dealership_id, 'client_id' => $client->id, 'description' => $data['description'], 'total' => Money::decimal($total), 'initial_amount' => Money::decimal($initial), 'frequency' => $data['frequency'], 'notes' => $data['notes'] ?? null]);
            $order->items()->createMany($items);
            $order->installments()->createMany($schedule);
            if ($initial > 0) {
                $order->installments()->create(['number' => 0, 'due_date' => today()->toDateString(), 'amount' => Money::decimal($initial)]);
            }
            Audit::log($actor->id, 'create', 'order', $order->id, ['total' => $order->total]);

            return $order;
        }, 3);
    }

    public function pay(User $actor, int $orderId, array $data): Payment
    {
        $clientId = Order::findOrFail($orderId)->client_id;

        return DB::transaction(function () use ($actor, $orderId, $data, $clientId) {
            Client::lockForUpdate()->findOrFail($clientId);
            $order = Order::lockForUpdate()->findOrFail($orderId);
            Gate::forUser($actor)->authorize('update', $order);
            DealershipContext::forWrite($actor, null, $order->dealership_id);
            if ($old = Payment::where('token', $data['token'])->first()) {
                if ($old->order_id != $orderId) {
                    $this->fail('Comprobante duplicado.');
                }

                return $old;
            }
            $amount = Money::cents($data['amount']);
            $receipts = Receipt::where('client_id', $clientId)->whereNull('voided_at')->orderBy('id')->lockForUpdate()->with('applications')->get();
            $credit = $receipts->sum('available_cents');
            $useCredit = ($data['payment_source'] ?? 'new') === 'credit';
            if ($credit > 0 && ! $useCredit && empty($data['new_payment'])) {
                $this->fail('El concesionario tiene saldo a favor. Utiliza ese saldo o marca Nuevo pago para registrar dinero nuevo.');
            }
            if ($useCredit && ($amount <= 0 || $amount > $credit)) {
                $this->fail('El monto a aplicar debe ser mayor a cero y no superar el saldo a favor disponible. Actualiza la venta para consultar el saldo actual.');
            }
            $limit = $order->balance_cents;
            if (! empty($data['installment_id'])) {
                $target = $order->installments->firstWhere('id', (int) $data['installment_id']);
                if (! $target) {
                    $this->fail('La cuota no pertenece al pedido.');
                }
                $limit = min($limit, $target->remaining_cents);
            }
            if ($order->cancelled || $amount <= 0 || $limit <= 0 || ($amount > $limit && ($useCredit || empty($data['allow_credit'])))) {
                $this->fail($useCredit ? 'El saldo aplicado debe ser mayor a cero y no superar la deuda de la venta o de la cuota seleccionada.' : 'El abono debe ser mayor a cero y no superar el saldo pendiente. Active guardar excedente para registrar saldo a favor.');
            }
            $service = app(ReceiptService::class);
            if ($useCredit) {
                $remaining = $amount;
                $first = null;
                foreach ($receipts as $receipt) {
                    $part = min($remaining, $receipt->available_cents);
                    if ($part <= 0) {
                        continue;
                    }
                    $payment = $service->apply($actor, $receipt, $orderId, array_merge($data, ['token' => $first ? (string) Str::uuid() : $data['token'], 'amount' => Money::decimal($part)]));
                    $first ??= $payment;
                    $remaining -= $part;
                    if ($remaining === 0) {
                        break;
                    }
                }

                return $first;
            }
            $receipt = $service->receive($actor, $clientId, array_merge($data, ['source_order_id' => $orderId]));

            return $service->apply($actor, $receipt, $orderId, array_merge($data, ['amount' => Money::decimal(min($amount, $limit))]));
        }, 3);
    }

    public function void(User $actor, Payment $payment, string $reason): void
    {
        abort_unless($actor->canManageUsers() && $actor->canAccessRecord($payment), 403);
        $clientId = Order::findOrFail($payment->order_id)->client_id;
        DB::transaction(function () use ($actor, $payment, $reason, $clientId) {
            Client::lockForUpdate()->findOrFail($clientId);
            $order = Order::lockForUpdate()->findOrFail($payment->order_id);
            Gate::forUser($actor)->authorize('update', $order);
            DealershipContext::forWrite($actor, null, $order->dealership_id);
            $p = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($p->voided_at) {
                return;
            }$p->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            Audit::log($actor->id, 'void', 'payment', $p->id, ['reason' => $reason]);
        }, 3);
    }
}
