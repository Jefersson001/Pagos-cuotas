<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role = 'admin', ?int $dealer = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $dealer]);
    }

    private function client(User $user): Client
    {
        return Client::create(['user_id' => $user->id, 'name' => 'Cliente catálogo', 'document' => (string) Str::uuid(), 'phone' => '0412']);
    }

    private function data(Client $client, array $items): array
    {
        return ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'items' => $items, 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()];
    }

    public function test_managers_create_edit_and_delete_products_and_validate_initial(): void
    {
        $admin = $this->user();
        $dealer = Dealership::firstOrFail();
        $v = ['brand' => 'Moto', 'model' => 'Bera', 'transmission' => 'sincronico', 'year' => 2026, 'color' => 'rojo', 'cash_price' => '800.00', 'price' => '1000.00', 'installment_count' => 12, 'initial_amount' => '200.00', 'active' => 1, 'dealership_id' => $dealer->id, 'user_id' => 999999, 'name' => 'Nombre manipulado'];
        $this->get(route('products.index'))->assertRedirect(route('login'));
        $this->actingAs($admin)->get(route('products.create'))->assertOk()->assertViewHas('product', fn ($product) => $product->installment_count === 5)->assertSee('Previsualización del plan de pagos');
        $this->post(route('products.store'), array_merge($v, ['initial_amount' => '1000.01']))->assertSessionHasErrors('initial_amount');
        $this->post(route('products.store'), $v)->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame($admin->id, $product->user_id);
        $this->assertSame('Moto Bera 2026 Sincrónico Rojo', $product->name);
        $this->assertSame('800.00', $product->cash_price);
        $this->assertSame(12, $product->installment_count);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('value="Bera"', false)->assertDontSee('Nombre del producto')->assertSee('type="radio"', false);
        $this->put(route('products.update', $product), array_merge($v, ['model' => 'Bera nueva', 'initial_amount' => '150.00']))->assertSessionHasNoErrors();
        $this->assertSame('150.00', $product->fresh()->initial_amount);
        $this->get(route('products.index', ['q' => 'nueva']))->assertOk()->assertViewHas('products', fn ($p) => $p->total() === 1);
        $this->delete(route('products.destroy', $product))->assertSessionHasNoErrors();
        $this->assertModelMissing($product);
    }

    public function test_vehicle_details_reject_invalid_transmission_color_year_prices_and_installment_count(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        $this->actingAs($this->user());
        $v = ['brand' => 'Bera', 'model' => 'BR 200', 'transmission' => 'automatico', 'year' => 2026, 'color' => 'negro', 'cash_price' => '800.00', 'price' => '1000.00', 'installment_count' => 12, 'initial_amount' => '200.00', 'active' => 1, 'dealership_id' => Dealership::first()->id];
        foreach (['brand' => '', 'model' => '', 'transmission' => 'otro', 'year' => 2028, 'color' => 'azul', 'cash_price' => '0', 'price' => '0', 'installment_count' => 0] as $field => $value) {
            $error = $field === 'price' ? 'initial_amount' : $field;
            $this->post(route('products.store'), array_merge($v, [$field => $value]))->assertSessionHasErrors($error);
        }
        $this->post(route('products.store'), array_merge($v, ['installment_count' => 121]))->assertSessionHasErrors('installment_count');
        $this->assertDatabaseCount('products', 0);
        $this->post(route('products.store'), $v)->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame('Bera BR 200 2026 Automático Negro', $product->name);
        $client = $this->client($this->user('user', $product->dealership_id));
        $this->get(route('products.options', ['client_id' => $client->id, 'q' => 'Negro']))->assertOk()->assertJsonPath('products.0.name', $product->name)->assertJsonPath('products.0.price', '1000.00');
        $this->post(route('orders.store'), $this->data($client, [['product_id' => $product->id, 'quantity' => 1]]))->assertSessionHasNoErrors();
        $this->assertSame('1000.00', Order::firstOrFail()->total);
        $this->assertSame('200.00', Order::firstOrFail()->initial_amount);
    }

    public function test_vehicle_migration_preserves_legacy_product_names_and_prices(): void
    {
        $product = Product::factory()->create(['name' => 'Producto anterior', 'price' => '1250.50', 'initial_amount' => '200.00']);
        $migration = require database_path('migrations/2026_10_28_152724_add_vehicle_details_to_products.php');
        $migration->down();
        $migration->up();
        $saved = DB::table('products')->find($product->id);
        $this->assertSame('Producto anterior', $saved->name);
        $this->assertSame('Producto anterior', $saved->model);
        $this->assertSame(125050, Money::cents($saved->cash_price));
        $this->assertSame(125050, Money::cents($saved->price));
        $this->assertNull($saved->brand);
        $this->assertNull($saved->installment_count);
    }

    public function test_advisors_share_their_dealership_catalog_but_cannot_change_it_or_access_another_dealer(): void
    {
        $dealer = Dealership::factory()->create();
        $other = Dealership::factory()->create();
        $manager = $this->user('dealership_admin', $dealer->id);
        $advisor = $this->user('user', $dealer->id);
        $product = Product::factory()->create(['user_id' => $manager->id, 'dealership_id' => $dealer->id]);
        $foreign = Product::factory()->create(['dealership_id' => $other->id]);
        $client = $this->client($advisor);
        $this->actingAs($advisor)->get(route('products.index'))->assertOk()->assertViewHas('products', fn ($p) => $p->count() === 1 && $p->first()->id === $product->id);
        $this->get(route('products.options', ['client_id' => $client->id]))->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $product->id);
        $this->get(route('products.create'))->assertForbidden();
        $this->put(route('products.update', $product), [])->assertForbidden();
        $this->actingAs($manager)->get(route('products.edit', $foreign))->assertForbidden();
        $this->delete(route('products.destroy', $foreign))->assertForbidden();
        $stranger = $this->user('user', $other->id);
        $this->actingAs($stranger)->get(route('products.options', ['client_id' => $client->id]))->assertForbidden();
    }

    public function test_sale_uses_product_prices_and_sums_initials_by_quantity_without_receiving_money(): void
    {
        $user = $this->user('user', Dealership::first()->id);
        $client = $this->client($user);
        $a = Product::factory()->create(['dealership_id' => $client->dealership_id, 'name' => 'Moto A', 'price' => '100.01', 'initial_amount' => '20.01']);
        $b = Product::factory()->create(['dealership_id' => $client->dealership_id, 'name' => 'Moto B', 'price' => '200.02', 'initial_amount' => '40.02']);
        $data = $this->data($client, [['product_id' => $a->id, 'quantity' => 2, 'unit_price' => '0.01'], ['product_id' => $b->id, 'quantity' => 3]]);
        $data['initial_amount'] = '0';
        $data['description'] = 'Descripción manipulada';
        $this->actingAs($user)->post(route('orders.store'), $data)->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('800.08', $order->total);
        $this->assertSame('160.08', $order->initial_amount);
        $this->assertSame('Moto A, Moto B', $order->description);
        $this->assertSame(80008, $order->balance_cents);
        $this->assertSame(16008, $order->installments->firstWhere('number', 0)->remaining_cents);
        $this->assertSame(['160.00', '160.00', '160.00', '160.00'], $order->installments->where('number', '>', 0)->pluck('amount')->values()->all());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->post(route('orders.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $a->update(['name' => 'Nombre nuevo', 'price' => '999.00', 'initial_amount' => '99.00']);
        $item = $order->items->firstWhere('product_id', $a->id);
        $this->assertSame('Moto A', $item->description);
        $this->assertSame('100.01', (string) $item->unit_price);
        $this->assertSame('20.01', (string) $item->initial_amount);
        $admin = $this->user();
        $this->actingAs($admin)->delete(route('products.destroy', $a))->assertSessionHasErrors('product');
        $this->assertModelExists($a);
    }

    public function test_foreign_inactive_and_missing_products_cannot_create_a_sale(): void
    {
        $user = $this->user('user', Dealership::first()->id);
        $client = $this->client($user);
        $foreign = Product::factory()->create(['dealership_id' => Dealership::factory()->create()->id]);
        $inactive = Product::factory()->create(['dealership_id' => $client->dealership_id, 'active' => false]);
        $this->actingAs($user);
        foreach ([$foreign->id, $inactive->id, 999999] as $id) {
            $this->post(route('orders.store'), $this->data($client, [['product_id' => $id, 'quantity' => 1]]))->assertSessionHasErrors('amount');
        }
        $this->post(route('orders.store'), $this->data($client, [['description' => 'Manual', 'unit_price' => '1', 'quantity' => 1]]))->assertSessionHasErrors('items.0.product_id');
        $this->get(route('products.options', ['client_id' => $client->id]))->assertJsonCount(0, 'products');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_validation_keeps_selected_products_and_derived_values_in_the_wizard(): void
    {
        $user = $this->user('user', Dealership::first()->id);
        $client = $this->client($user);
        $product = Product::factory()->create(['dealership_id' => $client->dealership_id, 'price' => '100.00', 'initial_amount' => '30.00']);
        $this->actingAs($user)->get(route('orders.create', ['client_id' => $client->id]))->assertOk()->assertDontSee('Descripción del pedido')->assertSee('Pedido');
        $data = $this->data($client, [['product_id' => $product->id, 'quantity' => 2]]);
        $data['frequency'] = 'invalid';
        $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHasErrors('frequency');
        $this->get(route('orders.create'))->assertOk()->assertViewHas('selectedItems', fn ($items) => count($items) === 1 && $items[0]['product_id'] === $product->id && $items[0]['quantity'] === 2 && $items[0]['initial_amount'] === '30.00');
        $data['items'][0]['product_id'] = ['invalid'];
        $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.product_id');
        $this->get(route('orders.create'))->assertOk()->assertViewHas('selectedItems', []);
    }

    public function test_large_product_quantities_fail_validation_without_overflowing_money(): void
    {
        $user = $this->user('user', Dealership::first()->id);
        $client = $this->client($user);
        $product = Product::factory()->create(['dealership_id' => $client->dealership_id, 'price' => '9999999999.99', 'initial_amount' => '0.00']);
        $data = $this->data($client, [['product_id' => $product->id, 'quantity' => 100000]]);
        $this->actingAs($user)->post(route('orders.store'), $data)->assertSessionHasErrors('amount');
        $product->update(['initial_amount' => $product->price]);
        $this->post(route('orders.store'), $data)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 0);
    }
}
