<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): array
    {
        $user = User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);
        $client = Client::create(['user_id' => $user->id, 'name' => 'Cliente de pagos', 'document' => (string) Str::uuid(), 'phone' => '04121234567']);

        return [$user, $client];
    }

    private function order(User $user, Client $client): Order
    {
        return app(SalesService::class)->create($user, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Moto Bera', 'items' => [['description' => 'Moto', 'quantity' => 1, 'unit_price' => '400.00']], 'initial_amount' => '0', 'payment_method_id' => PaymentMethod::first()->id, 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);
    }

    private function receive(User $user, Client $client, string $amount = '350.00'): Receipt
    {
        return app(ReceiptService::class)->receive($user, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => PaymentMethod::where('dealership_id', $client->dealership_id)->value('id')]);
    }

    public function test_partial_filter_only_lists_visible_confirmed_receipts_with_applied_and_available_money(): void
    {
        [$user, $client] = $this->customer();
        $order = $this->order($user, $client);
        $partial = $this->receive($user, $client, '100.00');
        $full = $this->receive($user, $client, '50.00');
        $this->receive($user, $client, '40.00');
        $voided = $this->receive($user, $client, '30.00');
        $voided->update(['voided_at' => now()]);
        $service = app(ReceiptService::class);
        $service->apply($user, $partial, $order->id, ['token' => (string) Str::uuid(), 'amount' => '20.00']);
        $service->apply($user, $full, $order->id, ['token' => (string) Str::uuid(), 'amount' => '50.00']);
        $this->actingAs($user)->get(route('receipts.index', ['state' => 'partial']))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $partial->id)->assertSee('Parcialmente aplicado');
        [$other] = $this->customer();
        $this->actingAs($other)->get(route('receipts.index', ['state' => 'partial']))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->total() === 0);
    }

    public function test_creating_a_sale_programs_the_initial_without_recording_cash_and_can_pay_it_later(): void
    {
        [$user, $client] = $this->customer();
        $product = Product::factory()->create(['user_id' => $user->id, 'dealership_id' => $client->dealership_id, 'price' => '500.00', 'initial_amount' => '100.00']);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Inicial pendiente', 'items' => [['product_id' => $product->id, 'description' => 'Moto', 'quantity' => 1, 'unit_price' => '500.00']], 'initial_amount' => '100.00', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->subMonth()->toDateString()];
        $this->actingAs($user)->post(route('orders.store'), $data)->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $initial = $order->installments->firstWhere('number', 0);
        $this->assertSame('Inicial', $initial->label);
        $this->assertSame(10000, $initial->remaining_cents);
        $this->assertSame(50000, $order->balance_cents);
        $this->assertSame(0, $order->paid_cents);
        $this->assertCount(5, $order->installments);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->post(route('orders.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('installment_schedules', 5);
        $this->get(route('collections'))->assertOk()->assertSee('Inicial');
        $this->post(route('orders.pay', $order), ['token' => (string) Str::uuid(), 'amount' => '40.00', 'installment_id' => $initial->id, 'payment_method_id' => PaymentMethod::first()->id])->assertSessionHasNoErrors();
        $this->assertSame(6000, $initial->fresh()->remaining_cents);
        $receipt = $this->receive($user, $client, '60.00');
        $this->post(route('orders.pay', $order), ['token' => (string) Str::uuid(), 'amount' => '60.00', 'installment_id' => $initial->id, 'payment_source' => 'credit'])->assertSessionHasNoErrors();
        $this->assertSame(0, $initial->fresh()->remaining_cents);
        $this->assertSame(40000, $order->fresh()->balance_cents);
        $this->assertSame(0, $receipt->fresh()->available_cents);
        $this->assertSame(10000, $order->fresh()->installments->firstWhere('number', 1)->remaining_cents);
    }

    public function test_initial_is_paid_before_installments_and_full_initial_still_requires_payment(): void
    {
        [$user, $client] = $this->customer();
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Inicial completa', 'items' => [['description' => 'Moto', 'quantity' => 1, 'unit_price' => '500.00']], 'initial_amount' => '100.00', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->subYear()->toDateString()];
        $order = app(SalesService::class)->create($user, $data);
        app(SalesService::class)->pay($user, $order->id, ['token' => (string) Str::uuid(), 'amount' => '150.00', 'payment_method_id' => PaymentMethod::first()->id]);
        $this->assertSame(0, $order->fresh()->installments->firstWhere('number', 0)->remaining_cents);
        $this->assertSame(5000, $order->fresh()->installments->firstWhere('number', 1)->remaining_cents);
        $full = app(SalesService::class)->create($user, array_merge($data, ['token' => (string) Str::uuid(), 'initial_amount' => '500.00']));
        $this->assertCount(1, $full->installments);
        $this->assertSame(50000, $full->balance_cents);
        $this->assertSame('Pendiente de pago', $full->status);
        app(SalesService::class)->pay($user, $full->id, ['token' => (string) Str::uuid(), 'amount' => '500.00', 'payment_method_id' => PaymentMethod::first()->id]);
        $this->assertSame('Pagado', $full->fresh()->status);
    }

    public function test_sale_uses_multiple_existing_receipts_without_receiving_more_cash_and_is_idempotent(): void
    {
        [$user, $client] = $this->customer();
        $order = $this->order($user, $client);
        $first = $this->receive($user, $client, '80.00');
        $second = $this->receive($user, $client, '220.00');
        $data = ['token' => (string) Str::uuid(), 'amount' => '250.00', 'payment_source' => 'credit'];
        $this->actingAs($user)->get(route('orders.show', $order))->assertOk()->assertSee('Saldo a favor disponible:')->assertSee('Nuevo pago');
        $this->post(route('orders.pay', $order), $data)->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $order), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('receipts', 2);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(0, $first->fresh()->available_cents);
        $this->assertSame(5000, $second->fresh()->available_cents);
        $this->assertSame(15000, $order->fresh()->balance_cents);
        $this->assertSame([0, 0, 5000, 10000], $order->fresh()->installments->map(fn ($i) => $i->remaining_cents)->all());
    }

    public function test_sale_requires_explicit_new_payment_choice_when_credit_is_available(): void
    {
        [$user, $client] = $this->customer();
        $order = $this->order($user, $client);
        $receipt = $this->receive($user, $client, '100.00');
        $data = ['token' => (string) Str::uuid(), 'amount' => '500.00', 'allow_credit' => 1, 'payment_method_id' => $receipt->payment_method_id];
        $this->actingAs($user)->post(route('orders.pay', $order), $data)->assertSessionHasErrors('amount');
        $this->post(route('orders.pay', $order), array_merge($data, ['payment_source' => 'credit', 'amount' => '100.01']))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->post(route('orders.pay', $order), array_merge($data, ['payment_source' => 'credit', 'amount' => '100.00']))->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $order), array_merge($data, ['token' => (string) Str::uuid(), 'amount' => '350.00']))->assertSessionHasNoErrors();
        $this->assertSame(0, $order->fresh()->balance_cents);
        $this->assertSame(5000, $client->fresh()->credit_cents);
        $this->assertDatabaseCount('receipts', 2);
    }

    public function test_explicit_new_payment_preserves_existing_credit_and_can_save_surplus(): void
    {
        [$user, $client] = $this->customer();
        $order = $this->order($user, $client);
        $receipt = $this->receive($user, $client, '100.00');
        $data = ['token' => (string) Str::uuid(), 'amount' => '450.00', 'new_payment' => 1, 'payment_source' => 'new', 'allow_credit' => 1];
        $this->actingAs($user)->post(route('orders.pay', $order), $data)->assertSessionHasErrors('payment_method_id');
        $data['payment_method_id'] = $receipt->payment_method_id;
        $this->post(route('orders.pay', $order), array_merge($data, ['allow_credit' => 0]))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('receipts', 1);
        $this->post(route('orders.pay', $order), $data)->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $order), $data)->assertSessionHasNoErrors();
        $this->assertSame(10000, $receipt->fresh()->available_cents);
        $this->assertSame(15000, $client->fresh()->credit_cents);
        $this->assertSame(0, $order->fresh()->balance_cents);
        $this->assertDatabaseCount('receipts', 2);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_sale_credit_respects_quota_limit_and_rejects_stale_or_foreign_credit(): void
    {
        [$user, $client] = $this->customer();
        $order = $this->order($user, $client);
        $receipt = $this->receive($user, $client);
        $data = ['token' => (string) Str::uuid(), 'payment_source' => 'credit', 'amount' => '100.01', 'installment_id' => $order->installments[1]->id, 'allow_credit' => 1];
        $this->actingAs($user)->post(route('orders.pay', $order), $data)->assertSessionHasErrors('amount');
        $this->post(route('orders.pay', $order), array_merge($data, ['amount' => '100.00']))->assertSessionHasNoErrors();
        $this->assertSame(0, $order->fresh()->installments[1]->remaining_cents);
        $this->assertSame(10000, $order->fresh()->installments[0]->remaining_cents);
        [$other, $foreign] = $this->customer();
        $foreignOrder = $this->order($other, $foreign);
        $this->post(route('orders.pay', $foreignOrder), $data)->assertForbidden();
        $this->actingAs($other)->post(route('orders.pay', $foreignOrder), array_merge($data, ['token' => (string) Str::uuid(), 'installment_id' => null, 'amount' => '1.00']))->assertSessionHasErrors('amount');
        $this->assertSame(25000, $receipt->fresh()->available_cents);
    }

    public function test_one_receipt_can_cover_multiple_installments_and_sales_without_counting_cash_twice(): void
    {
        [$user,$client] = $this->customer();
        $a = $this->order($user, $client);
        $b = $this->order($user, $client);
        $receipt = $this->receive($user, $client);
        $this->assertSame(35000, $client->fresh()->credit_cents);
        $service = app(ReceiptService::class);
        $service->apply($user, $receipt, $a->id, ['token' => (string) Str::uuid(), 'amount' => '250.00']);
        $service->apply($user, $receipt, $b->id, ['token' => (string) Str::uuid(), 'amount' => '80.00']);
        $this->assertSame([0, 0, 5000, 10000], $a->fresh()->installments->map(fn ($i) => $i->remaining_cents)->all());
        $this->assertSame(15000, $a->fresh()->balance_cents);
        $this->assertSame(32000, $b->fresh()->balance_cents);
        $this->assertSame(2000, $client->fresh()->credit_cents);
        $this->assertDatabaseCount('receipts', 1);
        $this->actingAs($user)->get(route('reports', ['type' => 'receipts']))->assertOk()->assertViewHas('total', 1)->assertSee('350.00')->assertSee('330.00')->assertSee('20.00');
        $this->get(route('clients.show', $client))->assertOk()->assertSee('Saldo a favor sin aplicar');
        $this->get(route('receipts.show', $receipt))->assertOk()->assertSee('Aplicaciones a ventas y cuotas');
    }

    public function test_direct_payment_stores_surplus_only_with_explicit_option(): void
    {
        [$user,$client] = $this->customer();
        $order = $this->order($user, $client);
        $data = ['token' => (string) Str::uuid(), 'amount' => '450.00', 'payment_method_id' => PaymentMethod::first()->id, 'allow_credit' => 1];
        $this->actingAs($user)->post(route('orders.pay', $order), $data)->assertSessionHasNoErrors();
        $this->assertSame(0, $order->fresh()->balance_cents);
        $this->assertSame(5000, $client->fresh()->credit_cents);
        $this->post(route('orders.pay', $order), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_standalone_payment_can_be_received_then_applied_idempotently(): void
    {
        [$user,$client] = $this->customer();
        $order = $this->order($user, $client);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'amount' => '250.00', 'payment_method_id' => PaymentMethod::first()->id, 'user_id' => 99999, 'dealership_id' => 99999];
        $this->actingAs($user)->post(route('receipts.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('receipts.store'), $data)->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->assertSame($client->user_id, $receipt->user_id);
        $this->assertSame($client->dealership_id, $receipt->dealership_id);
        $this->assertSame(40000, $order->fresh()->balance_cents);
        $apply = ['token' => (string) Str::uuid(), 'order_id' => $order->id, 'amount' => '230.00'];
        $this->post(route('receipts.apply', $receipt), $apply)->assertSessionHasNoErrors();
        $this->post(route('receipts.apply', $receipt), $apply)->assertSessionHasNoErrors();
        $this->assertSame(2000, $receipt->fresh()->available_cents);
        $this->assertDatabaseCount('payments', 1);
        $this->post(route('receipts.apply', $receipt), array_merge($apply, ['token' => (string) Str::uuid(), 'amount' => '20.01']))->assertSessionHasErrors('amount');
        $this->assertSame(2000, $receipt->fresh()->available_cents);
    }

    public function test_receive_and_apply_in_one_form_keeps_excess_and_resubmission_does_not_duplicate(): void
    {
        [$user,$client] = $this->customer();
        $order = $this->order($user, $client);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'amount' => '500.00', 'payment_method_id' => PaymentMethod::first()->id, 'order_id' => $order->id];
        $this->actingAs($user)->post(route('receipts.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(10000, $client->fresh()->credit_cents);
    }

    public function test_credit_cannot_be_transferred_to_a_different_client_or_accessed_by_other_advisors(): void
    {
        [$user,$client] = $this->customer();
        $receipt = $this->receive($user, $client);
        $other = Client::create(['user_id' => $user->id, 'name' => 'Otro', 'document' => 'OTHER', 'phone' => '0412']);
        $order = $this->order($user, $other);
        $data = ['token' => (string) Str::uuid(), 'order_id' => $order->id, 'amount' => '50.00'];
        $this->actingAs($user)->post(route('receipts.apply', $receipt), $data)->assertSessionHasErrors('amount');
        [$stranger] = $this->customer();
        $this->actingAs($stranger)->get(route('receipts.show', $receipt))->assertForbidden();
        $this->post(route('receipts.apply', $receipt), $data)->assertForbidden();
        $this->get(route('receipts.account', ['client_id' => $client->id]))->assertForbidden();
        $this->get(route('receipts.index'))->assertOk()->assertViewHas('received', 0);
        $this->assertSame(35000, $receipt->fresh()->available_cents);
    }

    public function test_unlinking_restores_credit_while_voiding_cash_removes_credit_and_restores_all_debts(): void
    {
        [$user,$client] = $this->customer();
        $a = $this->order($user, $client);
        $b = $this->order($user, $client);
        $receipt = $this->receive($user, $client);
        $service = app(ReceiptService::class);
        $p = $service->apply($user, $receipt, $a->id, ['token' => (string) Str::uuid(), 'amount' => '200.00']);
        $service->apply($user, $receipt, $b->id, ['token' => (string) Str::uuid(), 'amount' => '100.00']);
        $this->actingAs($user)->post(route('receipts.void', $receipt), ['reason' => 'Ingreso duplicado por error'])->assertForbidden();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        app(SalesService::class)->void($admin, $p, 'Se vinculó a la venta equivocada');
        $this->assertSame(25000, $receipt->fresh()->available_cents);
        $this->assertSame(40000, $a->fresh()->balance_cents);
        $this->actingAs($admin)->post(route('receipts.void', $receipt), ['reason' => 'Ingreso duplicado por error'])->assertSessionHasNoErrors();
        $this->assertSame(0, $receipt->fresh()->available_cents);
        $this->assertSame(40000, $b->fresh()->balance_cents);
        $this->post(route('receipts.apply', $receipt), ['token' => (string) Str::uuid(), 'order_id' => $a->id, 'amount' => '1.00'])->assertSessionHasErrors('amount');
    }

    public function test_specific_installment_and_inactive_method_do_not_lose_existing_funds(): void
    {
        [$user,$client] = $this->customer();
        $order = $this->order($user, $client);
        $receipt = $this->receive($user, $client);
        $receipt->method->update(['active' => false]);
        $data = ['token' => (string) Str::uuid(), 'order_id' => $order->id, 'installment_id' => $order->installments[1]->id, 'amount' => '100.00'];
        $this->actingAs($user)->post(route('receipts.apply', $receipt), $data)->assertSessionHasNoErrors();
        $this->assertSame(10000, $order->fresh()->installments[0]->remaining_cents);
        $this->assertSame(0, $order->fresh()->installments[1]->remaining_cents);
        $this->assertSame(25000, $receipt->fresh()->available_cents);
    }

    public function test_invalid_or_foreign_order_rolls_back_the_whole_receipt(): void
    {
        [$user,$client] = $this->customer();
        [$other,$foreign] = $this->customer();
        $order = $this->order($other, $foreign);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'order_id' => $order->id, 'amount' => '20.00', 'payment_method_id' => PaymentMethod::first()->id];
        $this->actingAs($user)->post(route('receipts.store'), $data)->assertForbidden();
        $this->assertDatabaseCount('receipts', 0);
        $this->post(route('receipts.store'), array_merge($data, ['order_id' => '', 'amount' => '-1']))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('receipts', 0);
        $this->get(route('receipts.create', ['client_id' => $client->id]))->assertOk();
        $this->get(route('receipts.account', ['client_id' => $client->id]))->assertOk()->assertJsonPath('debt', 0)->assertJsonPath('credit', 0);
    }

    public function test_dealerships_keep_receipts_private_and_methods_with_unapplied_cash_cannot_be_deleted(): void
    {
        [$user, $client] = $this->customer();
        $receipt = $this->receive($user, $client);
        $dealer = Dealership::factory()->create();
        $manager = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $dealer->id]);
        $this->actingAs($manager)->get(route('receipts.show', $receipt))->assertForbidden();
        $this->get(route('receipts.index'))->assertOk()->assertViewHas('received', 0);
        $this->post(route('receipts.void', $receipt), ['reason' => 'Intento desde otra sede'])->assertForbidden();
        $foreignMethod = PaymentMethod::create(['name' => 'Caja de otra sede', 'dealership_id' => $dealer->id]);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'amount' => '10.00', 'payment_method_id' => $foreignMethod->id];
        $this->actingAs($user)->post(route('receipts.store'), $data)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('receipts', 1);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $this->actingAs($admin)->delete(route('payment-methods.destroy', $receipt->method))->assertSessionHasErrors('method');
        $this->assertDatabaseHas('payment_methods', ['id' => $receipt->payment_method_id]);
        $this->assertSame(35000, $receipt->fresh()->available_cents);
    }
}
