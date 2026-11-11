<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin = true): User
    {
        $this->seed();

        return User::factory()->create(['role_id' => Role::where('name', $admin ? 'admin' : 'user')->firstOrFail()->id]);
    }

    public function test_admin_can_create_edit_and_delete_an_unused_method(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin);
        $this->get(route('payment-methods.create'))->assertOk();
        $this->post(route('payment-methods.store'), ['name' => 'Tarjeta bancaria', 'active' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $method = PaymentMethod::where('name', 'Tarjeta bancaria')->firstOrFail();
        $this->assertTrue($method->active);
        $this->get(route('payment-methods.edit', $method))->assertOk()->assertSee('Tarjeta bancaria');
        $this->put(route('payment-methods.update', $method), ['name' => 'Tarjeta de débito', 'active' => 0])->assertSessionHasNoErrors()->assertRedirect(route('payment-methods.edit', $method));
        $this->assertDatabaseHas('payment_methods', ['id' => $method->id, 'name' => 'Tarjeta de débito', 'active' => false]);
        $this->delete(route('payment-methods.destroy', $method))->assertRedirect(route('payment-methods.index'));
        $this->assertModelMissing($method);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'entity' => 'payment_method', 'entity_id' => $method->id, 'action' => 'delete']);
    }

    public function test_guest_and_normal_user_cannot_manage_methods(): void
    {
        $user = $this->actor(false);
        $method = PaymentMethod::firstOrFail();
        $requests = [['get', 'payment-methods.index', []], ['get', 'payment-methods.create', []], ['get', 'payment-methods.edit', [$method]], ['post', 'payment-methods.store', []], ['put', 'payment-methods.update', [$method]], ['delete', 'payment-methods.destroy', [$method]]];
        foreach ($requests as [$verb,$route,$parameters]) {
            $this->$verb(route($route, $parameters))->assertRedirect(route('login'));
        }
        $this->actingAs($user);
        foreach ($requests as [$verb,$route,$parameters]) {
            $this->$verb(route($route, $parameters))->assertForbidden();
        }
        $this->assertModelExists($method);
    }

    public function test_head_office_manages_only_its_organization_payment_methods(): void
    {
        $this->seed();
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $foreign = PaymentMethod::firstOrFail();
        $this->actingAs($head)->get(route('receipts.index'))->assertOk()->assertSee(route('payment-methods.index'), false);
        $this->get(route('payment-methods.create'))->assertOk();
        $this->post(route('payment-methods.store'), ['name' => 'Metodo central', 'active' => 1, 'dealership_id' => $foreign->dealership_id])->assertForbidden();
        $this->post(route('payment-methods.store'), ['name' => 'Metodo central', 'active' => 1])->assertSessionHasNoErrors();
        $method = PaymentMethod::where('name', 'Metodo central')->firstOrFail();
        $this->assertSame($company->id, $method->dealership_id);
        $this->get(route('payment-methods.index', ['dealership_id' => $foreign->dealership_id]))->assertOk()->assertViewHas('methods', fn ($methods) => $methods->total() === 1 && $methods->first()->id === $method->id);
        $this->get(route('payment-methods.show', $method))->assertOk()->assertSee('Duplicar');
        $this->get(route('payment-methods.edit', $method))->assertOk();
        $this->put(route('payment-methods.update', $method), ['name' => 'Metodo actualizado', 'active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($method->fresh()->active);
        $this->get(route('records.duplicate', ['type' => 'method', 'id' => $method->id]))->assertRedirect(route('payment-methods.create'));
        $this->get(route('payment-methods.show', $foreign))->assertForbidden();
        $this->get(route('payment-methods.edit', $foreign))->assertForbidden();
        $this->put(route('payment-methods.update', $foreign), ['name' => 'No permitido', 'active' => 0])->assertForbidden();
        $this->delete(route('payment-methods.destroy', $foreign))->assertForbidden();
        $this->get(route('records.duplicate', ['type' => 'method', 'id' => $foreign->id]))->assertForbidden();
        $this->delete(route('records.destroy', ['type' => 'method', 'id' => $method->id]))->assertRedirect(route('payment-methods.index'));
        $this->assertModelMissing($method);
        $this->assertModelExists($foreign);
    }

    public function test_concessionaire_cannot_manage_methods_but_can_select_them_when_registering_payments(): void
    {
        $this->seed();
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $contact = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $contact->id]);
        $client = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $contact->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia central', 'active' => true]);
        $this->actingAs($dealer)->get(route('workspace.sales'))->assertOk()->assertDontSee(route('payment-methods.index'), false);
        $this->get(route('receipts.index'))->assertOk()->assertDontSee(route('payment-methods.index'), false);
        foreach (['payment-methods.index', 'payment-methods.create'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        foreach (['payment-methods.show', 'payment-methods.edit'] as $route) {
            $this->get(route($route, $method))->assertForbidden();
        }
        $this->post(route('payment-methods.store'), ['name' => 'No permitido', 'active' => 1])->assertForbidden();
        $this->put(route('payment-methods.update', $method), ['name' => 'No permitido', 'active' => 0])->assertForbidden();
        $this->delete(route('payment-methods.destroy', $method))->assertForbidden();
        $this->get(route('records.duplicate', ['type' => 'method', 'id' => $method->id]))->assertForbidden();
        $this->delete(route('records.destroy', ['type' => 'method', 'id' => $method->id]))->assertForbidden();
        $this->get(route('receipts.account', ['client_id' => $client->id]))->assertOk()->assertJsonPath('methods.0.id', $method->id);
        $this->post(route('receipts.store'), ['payment_date' => today()->toDateString(), 'token' => (string) Str::uuid(), 'client_id' => $client->id, 'payment_method_id' => $method->id, 'amount' => '10.00'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('receipts', ['client_id' => $client->id, 'payment_method_id' => $method->id, 'approval_status' => 'pending']);
        $this->assertTrue($method->fresh()->active);
    }

    public function test_invalid_names_states_and_duplicate_names_are_rejected(): void
    {
        $this->actingAs($this->actor());
        $this->post(route('payment-methods.store'), [])->assertSessionHasErrors(['name', 'active']);
        $this->post(route('payment-methods.store'), ['name' => 'Efectivo', 'active' => 1])->assertSessionHasErrors('name');
        $this->post(route('payment-methods.store'), ['name' => str_repeat('a', 81), 'active' => 3])->assertSessionHasErrors(['name', 'active']);
        $this->assertDatabaseCount('payment_methods', 6);
    }

    public function test_used_methods_cannot_be_deleted_but_can_be_disabled(): void
    {
        $admin = $this->actor();
        $method = PaymentMethod::firstOrFail();
        $client = Client::create(['user_id' => $admin->id, 'name' => 'Cliente', 'document' => 'METHOD-TEST', 'phone' => '04120000000']);
        $order = app(SalesService::class)->create($admin, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Prueba método', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '10.00', 'payment_method_id' => $method->id, 'installment_count' => 1, 'frequency' => 'monthly', 'first_due' => '2027-01-01']);
        $payment = app(SalesService::class)->pay($admin, $order->id, ['payment_date' => today()->toDateString(), 'token' => (string) Str::uuid(), 'amount' => '10.00', 'payment_method_id' => $method->id]);
        $this->actingAs($admin)->from(route('payment-methods.edit', $method))->delete(route('payment-methods.destroy', $method))->assertSessionHasErrors('method');
        $this->assertModelExists($method);
        $this->assertModelExists($payment);
        $this->put(route('payment-methods.update', $method), ['name' => $method->name, 'active' => 0])->assertSessionHasNoErrors();
        $this->get(route('orders.show', $order))->assertOk()->assertSee($method->name);
        $this->assertFalse($method->fresh()->active);
    }

    public function test_list_filters_and_escapes_names(): void
    {
        $this->actingAs($this->actor());
        $name = '<script>alert(1)</script>';
        $method = PaymentMethod::create(['name' => $name, 'active' => false]);
        $this->get(route('payment-methods.index', ['active' => 0, 'q' => 'alert']))->assertOk()->assertSee($name)->assertDontSee($name, false)->assertDontSee('Efectivo');
        $this->assertFalse($method->fresh()->active);
        $this->assertTrue(PaymentMethod::where('name', 'Efectivo')->firstOrFail()->active);
    }
}
