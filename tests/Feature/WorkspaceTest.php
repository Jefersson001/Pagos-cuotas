<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceTest extends TestCase
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

    public function test_home_requires_login_and_has_five_real_modules(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->actingAs($this->admin())->get(route('home'))->assertOk()->assertSee('Tu espacio de trabajo')->assertSee('data-module-card', false)->assertSee('Clientes')->assertSee('Productos')->assertSee('Reportes')->assertSee('Ajustes')->assertViewHas('modules', fn ($modules) => count($modules) === 5 && ! isset($modules['summary']));
        foreach (['workspace.customers', 'workspace.products', 'workspace.sales', 'reports', 'workspace.settings'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_login_redirects_to_module_launcher(): void
    {
        $user = $this->admin();
        $user->update(['password' => 'Test-password-12345']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Test-password-12345'])->assertRedirect(route('home'));
    }

    public function test_payment_navigation_groups_receipts_and_methods_under_sales(): void
    {
        $this->actingAs($this->admin())->get(route('workspace.sales'))->assertOk()
            ->assertSee('class="workspace-nav-group"', false)
            ->assertSee('href="'.route('receipts.index').'"', false)
            ->assertSee('href="'.route('payment-methods.index').'"', false);

        $this->get(route('payment-methods.create'))->assertOk()
            ->assertSee('<span class="workspace-module-title">Ventas</span>', false)
            ->assertSee('<summary class="is-active">Pagos', false);

        $this->get(route('workspace.settings'))->assertOk()
            ->assertDontSee('Métodos de pago')
            ->assertDontSee('href="'.route('payment-methods.index').'"', false);
    }

    public function test_advisors_can_open_payments_without_method_management_links(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);

        $this->actingAs($user)->get(route('receipts.index'))->assertOk()
            ->assertSee('href="'.route('receipts.index').'"', false)
            ->assertDontSee('href="'.route('payment-methods.index').'"', false);
        $this->get(route('payment-methods.index'))->assertForbidden();
    }

    public function test_contacts_share_one_list_and_can_be_filtered_by_type(): void
    {
        $user = $this->admin();
        $this->actingAs($user);
        $this->post(route('clients.store'), ['name' => 'Cliente general', 'customer_kind' => 'person'])->assertSessionHasNoErrors();
        $this->post(route('clients.store'), ['name' => 'Concesionario de prueba', 'customer_kind' => 'dealership', 'document' => 'J-123', 'condition' => 'ACTIVO'])->assertSessionHasNoErrors();
        $this->get(route('clients.index'))->assertViewHas('clients', fn ($rows) => $rows->total() === 2);
        $this->get(route('clients.index', ['customer_kind' => 'person']))->assertViewHas('clients', fn ($rows) => $rows->total() === 1 && $rows->first()->name === 'Cliente general');
        $this->get(route('clients.index', ['customer_kind' => 'dealership']))->assertViewHas('clients', fn ($rows) => $rows->total() === 1 && $rows->first()->name === 'Concesionario de prueba');
    }

    public function test_generic_product_accepts_photo_and_has_no_required_vehicle_fields(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $file = UploadedFile::fake()->createWithContent('producto.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1EAAAAASUVORK5CYII='));
        $this->post(route('products.store'), ['product_type' => 'general', 'name' => 'Producto estándar', 'price' => '200.00', 'initial_amount' => '50.00', 'image' => $file])->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame('Producto estándar', $product->name);
        $this->assertSame(5, $product->installment_count);
        $this->assertNull($product->transmission);
        Storage::disk('public')->assertExists($product->image_path);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('Producto estándar');
        $this->post(route('products.store'), ['name' => 'Plan inválido', 'price' => '1.00', 'initial_amount' => '0.99', 'installment_count' => 5])->assertSessionHasErrors('installment_count');
    }

    public function test_catalog_changes_require_review_before_confirming_a_sale(): void
    {
        $user = $this->admin();
        $this->actingAs($user);
        $this->post(route('clients.store'), ['name' => 'Comprador'])->assertSessionHasNoErrors();
        $client = Client::firstOrFail();
        $this->post(route('products.store'), ['name' => 'Servicio', 'product_type' => 'service', 'price' => '100.00', 'initial_amount' => '0', 'installment_count' => 5])->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $version = $product->catalog_version;
        $product->update(['price' => '120.00']);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'catalog_version' => $version]], 'installment_count' => 5, 'frequency' => 'monthly', 'first_due' => today()->toDateString()];
        $this->post(route('orders.store'), $data)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 0);
        $data['items'][0]['catalog_version'] = $product->fresh()->catalog_version;
        $this->post(route('orders.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame('120.00', Order::firstOrFail()->total);
    }

    public function test_regular_users_cannot_modify_settings(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);
        $this->actingAs($user)->put(route('workspace.settings'), ['name' => 'Cambio no autorizado'])->assertStatus(405);
        $this->get(route('home'))->assertOk();
    }
}
