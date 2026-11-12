<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    }

    private function user(string $role = 'admin', ?int $company = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company]);
    }

    /** @param array<int, array{product_id: int, quantity: int}> $items */
    private function sale(User $owner, array $items): Order
    {
        $client = Client::create(['user_id' => $owner->id, 'dealership_id' => $owner->dealership_id, 'name' => 'Comprador', 'document' => (string) Str::uuid(), 'phone' => '04121234567', 'active' => true]);

        return app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'items' => $items, 'installment_count' => 5, 'frequency' => 'monthly', 'first_due' => '2026-11-01']);
    }

    public function test_head_office_sees_organization_product_summary_without_private_customer_details(): void
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = $this->user('head_office', $company->id);
        $owner = $this->user('user', $company->id);
        $dealer = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $product = Product::factory()->create(['dealership_id' => $company->id, 'user_id' => $head->id, 'price' => '100.00', 'initial_amount' => '20.00']);
        foreach ([2, 3] as $quantity) {
            $order = $this->sale($owner, [['product_id' => $product->id, 'quantity' => $quantity]]);
            $order->client->update(['name' => 'CLIENTE PRIVADO', 'concessionaire_id' => $dealer->id]);
        }
        $foreignCompany = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreign = Product::factory()->create(['dealership_id' => $foreignCompany->id, 'name' => 'CATALOGO AJENO']);
        $this->sale($this->user('user', $foreignCompany->id), [['product_id' => $foreign->id, 'quantity' => 10]]);
        $this->actingAs($head)->get(route('workspace.products', ['dealership_id' => $foreignCompany->id]))->assertOk()
            ->assertViewHas('total', 1)->assertViewHas('unitsMonth', 5)->assertViewHas('soldMonthCents', 50000)
            ->assertSee('Resumen de productos')->assertSee('Ver catálogo')->assertSee('Nuevo producto')
            ->assertDontSee('CLIENTE PRIVADO')->assertDontSee('CATALOGO AJENO');
        $this->get(route('home'))->assertOk()->assertViewHas('modules', fn ($modules) => $modules['products']['children'] === [['name' => 'Resumen', 'route' => 'workspace.products'], ['name' => 'Productos', 'route' => 'products.index']]);
        $this->get(route('orders.index'))->assertForbidden();
        $this->get(route('clients.show', $order->client))->assertForbidden();
    }

    public function test_summary_uses_historical_prices_excludes_cancelled_and_future_sales_and_keeps_catalog_statistics_current(): void
    {
        $owner = $this->user('user');
        $general = Product::factory()->create(['user_id' => $owner->id, 'price' => '100.01', 'initial_amount' => '20.00', 'installment_count' => 5, 'sku' => 'EQ-1', 'image_path' => 'products/example.png']);
        $vehicle = Product::factory()->create(['user_id' => $owner->id, 'product_type' => 'vehicle', 'price' => '200.00', 'initial_amount' => '50.00', 'installment_count' => 5]);
        $service = Product::factory()->create(['user_id' => $owner->id, 'name' => '<script>alert("catalog")</script>', 'product_type' => 'service', 'price' => '300.00', 'initial_amount' => '300.00', 'installment_count' => 3]);
        Product::factory()->create(['user_id' => $owner->id, 'price' => '900.00', 'active' => false]);
        $this->sale($owner, [['product_id' => $general->id, 'quantity' => 4]])->update(['created_at' => '2026-09-30 23:59:59']);
        $this->sale($owner, [['product_id' => $general->id, 'quantity' => 2], ['product_id' => $vehicle->id, 'quantity' => 1]])->update(['created_at' => '2026-10-01 00:00:00']);
        $this->sale($owner, [['product_id' => $general->id, 'quantity' => 1]]);
        $this->sale($owner, [['product_id' => $general->id, 'quantity' => 10]])->update(['cancelled' => true]);
        $this->sale($owner, [['product_id' => $general->id, 'quantity' => 20]])->update(['created_at' => '2026-10-29 12:00:01']);
        $manual = $this->sale($owner, [['product_id' => $general->id, 'quantity' => 30]]);
        $manual->items()->update(['product_id' => null]);
        $general->update(['price' => '150.00']);
        $vehicle->update(['active' => false]);

        $response = $this->actingAs($owner)->get(route('workspace.products'))->assertOk();
        $data = $response->original->getData();

        $this->assertSame(4, $data['total']);
        $this->assertSame(2, $data['active']);
        $this->assertSame(4, $data['unitsMonth']);
        $this->assertSame(50003, $data['soldMonthCents']);
        $this->assertSame(2, $data['ordersMonth']);
        $this->assertSame(2, $data['sellingProducts']);
        $this->assertSame(22500, $data['averagePriceCents']);
        $this->assertSame(15000, $data['minPriceCents']);
        $this->assertSame(30000, $data['maxPriceCents']);
        $this->assertSame(2000, $data['averageInitialCents']);
        $this->assertSame(1, $data['financedCount']);
        $this->assertSame(5, $data['mostUsedPlan']);
        $this->assertSame(1, $data['withoutImage']);
        $this->assertSame(1, $data['withoutSku']);
        $this->assertSame(1, $data['neverSold']);
        $this->assertSame(1, $data['withoutMovement']);
        $this->assertSame($service->id, $data['quietProducts']->first()['product']->id);
        $this->assertSame($general->id, $data['ranking']->first()['product']->id);
        $this->assertSame(30003, $data['ranking']->first()['sold_month_cents']);
        $this->assertSame([40002, 0, 0, 0, 10001], $data['weeks']->pluck('amount')->all());
        $this->assertSame(['general' => 2, 'vehicle' => 1, 'service' => 1], $data['types']->map(fn ($group) => $group['total'])->all());
        $response->assertSee('Resumen de productos')->assertSee('Sin movimiento en el período')->assertSee($service->name)->assertDontSee($service->name, false);
    }

    public function test_catalog_is_shared_by_company_but_sales_obey_advisor_and_manager_permissions(): void
    {
        $owner = $this->user('user');
        $colleague = $this->user('user', $owner->dealership_id);
        $foreignCompany = Dealership::factory()->create();
        $foreignOwner = $this->user('user', $foreignCompany->id);
        $local = Product::factory()->create(['user_id' => $owner->id, 'price' => '100.00']);
        Product::factory()->create(['user_id' => $colleague->id, 'name' => 'Catálogo compartido']);
        $foreign = Product::factory()->create(['user_id' => $foreignOwner->id, 'name' => 'CATALOGO RESERVADO', 'price' => '100.00']);
        $this->sale($owner, [['product_id' => $local->id, 'quantity' => 1]]);
        $this->sale($colleague, [['product_id' => $local->id, 'quantity' => 1]]);
        $this->sale($foreignOwner, [['product_id' => $foreign->id, 'quantity' => 1]]);

        $this->actingAs($owner)->get(route('workspace.products', ['dealership_id' => $foreignCompany->id]))->assertOk()->assertDontSee('CATALOGO RESERVADO')->assertDontSee('Nuevo producto')->assertViewHas('total', 2)->assertViewHas('soldMonthCents', 10000);
        $manager = $this->user('dealership_admin', $owner->dealership_id);
        $contact = Client::factory()->concessionaire()->for($manager, 'owner')->create(['dealership_id' => $owner->dealership_id]);
        Client::where('dealership_id', $owner->dealership_id)->where('customer_kind', 'person')->update(['concessionaire_id' => $contact->id]);
        $manager->update(['concessionaire_id' => $contact->id]);
        $this->actingAs($manager)->get(route('workspace.products'))->assertOk()->assertDontSee('Nuevo producto')->assertViewHas('total', 2)->assertViewHas('soldMonthCents', 20000);
        $this->actingAs($this->user())->get(route('workspace.products'))->assertOk()->assertSee('CATALOGO RESERVADO')->assertViewHas('total', 3)->assertViewHas('soldMonthCents', 30000);
        $this->get(route('workspace.products', ['dealership_id' => $owner->dealership_id]))->assertOk()->assertDontSee('CATALOGO RESERVADO')->assertViewHas('total', 2)->assertViewHas('soldMonthCents', 20000);
    }

    public function test_empty_catalog_requires_login_and_month_resets_on_first_day(): void
    {
        $this->get(route('workspace.products'))->assertRedirect(route('login'));
        $owner = $this->user();
        $this->actingAs($owner)->get(route('workspace.products'))->assertOk()->assertViewHas('total', 0)->assertViewHas('averagePriceCents', 0)->assertViewHas('mostUsedPlan', null)->assertSee('Todavía no hay ventas de productos en el período.');

        $product = Product::factory()->create(['user_id' => $owner->id, 'price' => '100.00']);
        $this->sale($owner, [['product_id' => $product->id, 'quantity' => 1]])->update(['created_at' => '2026-12-31 23:59:59']);
        $this->travelTo(now()->setDate(2027, 1, 1)->startOfDay());

        $this->get(route('workspace.products'))->assertOk()->assertViewHas('soldMonthCents', 0)->assertViewHas('neverSold', 0)->assertViewHas('weeks', fn ($weeks) => $weeks->count() === 1 && $weeks->first()['amount'] === 0)->assertSee('01/01/2027');
    }
}
