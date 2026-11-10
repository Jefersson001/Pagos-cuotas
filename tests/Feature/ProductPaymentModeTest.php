<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPaymentModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
    }

    /** @param array<int, array<string, int|string>> $items */
    private function sale(User $actor, Client $client, array $items): Order
    {
        return app(SalesService::class)->create($actor, ['token' => (string) Str::uuid(), 'client_id' => $client->id,
            'items' => $items, 'initial_amount' => '0', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->addMonth()->toDateString()]);
    }

    public function test_service_can_require_full_payment_with_only_its_sale_price(): void
    {
        $this->actingAs($this->admin())->post(route('products.store'), ['product_type' => 'service', 'name' => 'Servicio de contado', 'price' => '125.50', 'pay_in_installments' => 0])->assertSessionHasNoErrors();
        $product = Product::where('name', 'Servicio de contado')->firstOrFail();
        $this->assertFalse($product->pay_in_installments);
        $this->assertSame('125.50', $product->cash_price);
        $this->assertSame('125.50', $product->initial_amount);
        $this->assertSame(1, $product->installment_count);
        $this->assertTrue($product->active);
        $this->post(route('products.store'), ['name' => 'Precio inválido', 'price' => '0', 'pay_in_installments' => 0])->assertSessionHasErrors('price');
        $this->post(route('products.store'), ['name' => 'Modo inválido', 'price' => '10', 'pay_in_installments' => 'otro'])->assertSessionHasErrors('pay_in_installments');
    }

    public function test_full_payment_overrides_financing_values_and_preserves_hidden_details(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->for($admin, 'owner')->create(['description' => 'Descripción existente', 'image_path' => 'products/existing.webp', 'active' => false]);
        $this->actingAs($admin)->put(route('products.update', $product), ['name' => $product->name, 'price' => '200', 'pay_in_installments' => 0, 'cash_price' => '50', 'initial_amount' => '0', 'installment_count' => 120])->assertSessionHasNoErrors();
        $product->refresh();
        $this->assertFalse($product->pay_in_installments);
        $this->assertSame('200.00', $product->initial_amount);
        $this->assertSame('200.00', $product->cash_price);
        $this->assertSame(1, $product->installment_count);
        $this->assertFalse($product->active);
        $this->assertSame('Descripción existente', $product->description);
        $this->assertSame('products/existing.webp', $product->image_path);
        $this->get(route('records.duplicate', ['type' => 'product', 'id' => $product->id]))->assertRedirect(route('products.create'))->assertSessionHasInput('pay_in_installments', false);
    }

    public function test_installments_remain_the_default_and_can_be_enabled_again(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->for($admin, 'owner')->create();
        $this->assertTrue($product->pay_in_installments);
        $client = Client::factory()->for($admin, 'owner')->create();
        $order = $this->sale($admin, $client, [['product_id' => $product->id, 'quantity' => 1]]);
        $this->assertSame(4, $order->installments()->where('number', '>', 0)->count());
        $product->update(['pay_in_installments' => false]);
        $this->actingAs($admin)->put(route('products.update', $product), ['name' => $product->name, 'price' => '200', 'pay_in_installments' => 1, 'initial_amount' => '20', 'installment_count' => 6])->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->pay_in_installments);
        $this->assertSame('20.00', $product->fresh()->initial_amount);
        $this->assertSame(6, $product->fresh()->installment_count);
    }

    public function test_full_payment_products_cannot_be_financed_even_with_manipulated_sale_values(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->for($admin, 'owner')->create();
        $product = Product::factory()->for($admin, 'owner')->create(['pay_in_installments' => false, 'price' => '125', 'initial_amount' => '0']);
        $order = $this->sale($admin, $client, [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '1', 'initial_amount' => '0']]);
        $this->assertSame('250.00', $order->total);
        $this->assertSame('0.00', $order->initial_amount);
        $this->assertSame(0, $order->installments()->where('number', '>', 0)->count());
        $this->assertSame(0, $order->installments()->where('number', 0)->count());
        $directPayment = $order->installments()->whereNotNull('order_item_id')->firstOrFail();
        $this->assertNull($directPayment->number);
        $this->assertSame('250.00', $directPayment->amount);
        $this->assertSame('Pago completo: '.$product->name, $directPayment->label);
        $this->assertSame(0, $order->payments()->count());
        $this->assertSame(25000, $order->balance_cents);
    }

    public function test_mixed_sales_only_finance_the_eligible_products_balance(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->for($admin, 'owner')->create();
        $cash = Product::factory()->for($admin, 'owner')->create(['pay_in_installments' => false, 'price' => '100', 'initial_amount' => '0']);
        $financed = Product::factory()->for($admin, 'owner')->create(['price' => '300', 'initial_amount' => '60']);
        $order = $this->sale($admin, $client, [['product_id' => $cash->id, 'quantity' => 2], ['product_id' => $financed->id, 'quantity' => 1]]);
        $this->assertSame('500.00', $order->total);
        $this->assertSame('60.00', $order->initial_amount);
        $this->assertSame('200.00', $order->installments()->whereNotNull('order_item_id')->firstOrFail()->amount);
        $this->assertSame(24000, $order->installments->where('number', '>', 0)->sum(fn ($row) => Money::cents($row->amount)));
        $this->assertSame(0, Money::cents($order->items->firstWhere('product_id', $cash->id)->initial_amount));
        app(SalesService::class)->pay($admin, $order->id, ['token' => (string) Str::uuid(), 'amount' => '260.00', 'payment_method_id' => PaymentMethod::where('dealership_id', $client->dealership_id)->value('id')]);
        $order->refresh();
        $this->assertSame(0, $order->installments()->where('number', 0)->firstOrFail()->remaining_cents);
        $this->assertSame(0, $order->installments()->whereNotNull('order_item_id')->firstOrFail()->remaining_cents);
        $this->assertSame(24000, $order->installments->where('number', '>', 0)->sum('remaining_cents'));
        $this->assertSame(24000, $order->balance_cents);
    }

    public function test_lookup_and_sale_prefill_require_full_price_for_cash_products(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->for($admin, 'owner')->create();
        $product = Product::factory()->for($admin, 'owner')->create(['pay_in_installments' => false, 'price' => '125', 'initial_amount' => '0']);
        $this->actingAs($admin)->getJson(route('products.options', ['client_id' => $client->id]))->assertOk()->assertJsonPath('products.0.pay_in_installments', false)->assertJsonPath('products.0.initial_amount', '0.00')->assertJsonPath('products.0.installment_count', 1);
        $this->withSession(['_old_input' => ['client_id' => $client->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]]])->get(route('orders.create'))->assertOk()->assertViewHas('selectedItems', fn ($items) => $items[0]['initial_amount'] === '0.00' && $items[0]['pay_in_installments'] === false);
    }

    public function test_each_cash_product_has_its_own_payable_balance_and_payment_can_be_voided(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->for($admin, 'owner')->create();
        $first = Product::factory()->for($admin, 'owner')->create(['dealership_id' => $client->dealership_id, 'pay_in_installments' => false, 'price' => '50']);
        $second = Product::factory()->for($admin, 'owner')->create(['dealership_id' => $client->dealership_id, 'pay_in_installments' => false, 'price' => '80']);
        $order = $this->sale($admin, $client, [['product_id' => $first->id, 'quantity' => 2], ['product_id' => $second->id, 'quantity' => 1]]);
        $this->assertSame('0.00', $order->initial_amount);
        $this->assertSame(2, $order->installments()->whereNull('number')->count());
        $target = $order->installments->firstWhere('order_item_id', $order->items->firstWhere('product_id', $second->id)->id);
        $payment = app(SalesService::class)->pay($admin, $order->id, ['token' => (string) Str::uuid(), 'amount' => '80.00', 'installment_id' => $target->id, 'payment_method_id' => PaymentMethod::where('dealership_id', $client->dealership_id)->value('id')]);
        $this->assertSame(0, $target->fresh()->remaining_cents);
        $this->assertSame(10000, $order->fresh()->balance_cents);
        $this->assertSame(10000, $order->fresh()->installments->firstWhere('order_item_id', $order->items->firstWhere('product_id', $first->id)->id)->remaining_cents);
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()->assertSee('Compromisos de pago')->assertSee($target->label);
        app(SalesService::class)->void($admin, $payment, 'Anular pago de prueba');
        $this->assertSame(8000, $target->fresh()->remaining_cents);
        $this->assertSame(18000, $order->fresh()->balance_cents);
    }

    public function test_payment_mode_changes_invalidate_old_catalog_versions(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->for($admin, 'owner')->create();
        $product = Product::factory()->for($admin, 'owner')->create();
        $version = $product->catalog_version;
        $product->update(['pay_in_installments' => false]);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'catalog_version' => $version]], 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()];
        $this->actingAs($admin)->post(route('orders.store'), $data)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 0);
        $this->assertNotSame($version, $product->fresh()->catalog_version);
    }

    public function test_unpaid_cash_sale_can_be_deleted_with_its_independent_payment_concepts(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->for($admin, 'owner')->create();
        $product = Product::factory()->for($admin, 'owner')->create(['dealership_id' => $client->dealership_id, 'pay_in_installments' => false]);
        $order = $this->sale($admin, $client, [['product_id' => $product->id, 'quantity' => 1]]);
        $this->actingAs($admin)->delete(route('records.destroy', ['type' => 'order', 'id' => $order->id]))->assertRedirect(route('orders.index'))->assertSessionHasNoErrors();
        $this->assertModelMissing($order);
        $this->assertDatabaseMissing('installment_schedules', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id]);
        $this->assertModelExists($product);
    }

    public function test_automatic_payments_cover_cash_products_before_the_initial_and_installments(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->for($admin, 'owner')->create();
        $cash = Product::factory()->for($admin, 'owner')->create(['dealership_id' => $client->dealership_id, 'name' => 'Servicio prioritario', 'pay_in_installments' => false, 'price' => '200']);
        $financed = Product::factory()->for($admin, 'owner')->create(['dealership_id' => $client->dealership_id, 'price' => '300', 'initial_amount' => '60']);
        $order = $this->sale($admin, $client, [['product_id' => $financed->id, 'quantity' => 1], ['product_id' => $cash->id, 'quantity' => 1]]);
        $order->installments()->where('number', 1)->update(['due_date' => today()->subDay()]);
        $this->assertSame([null, 0, 1, 2, 3, 4], $order->fresh()->installments->pluck('number')->all());
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()->assertSeeInOrder(['Compromisos de pago', 'Pago completo: Servicio prioritario', 'Inicial', 'Cuota 1']);
        $methodId = PaymentMethod::where('dealership_id', $client->dealership_id)->value('id');
        $service = app(SalesService::class);
        $service->pay($admin, $order->id, ['token' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method_id' => $methodId]);
        $this->assertSame(10000, $order->installments()->whereNotNull('order_item_id')->firstOrFail()->remaining_cents);
        $this->assertSame(6000, $order->installments()->where('number', 0)->firstOrFail()->remaining_cents);
        $this->assertSame(24000, $order->fresh()->installments->where('number', '>', 0)->sum('remaining_cents'));
        $service->pay($admin, $order->id, ['token' => (string) Str::uuid(), 'amount' => '160.00', 'payment_source' => 'new', 'new_payment' => true, 'payment_method_id' => $methodId]);
        $this->assertSame(0, $order->installments()->whereNotNull('order_item_id')->firstOrFail()->remaining_cents);
        $this->assertSame(0, $order->installments()->where('number', 0)->firstOrFail()->remaining_cents);
        $this->assertSame(24000, $order->fresh()->installments->where('number', '>', 0)->sum('remaining_cents'));
    }
}
