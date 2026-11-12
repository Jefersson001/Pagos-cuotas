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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessRolesTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $testPasswordHash = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    }

    private function actor(string $role, ?Dealership $company = null, ?Client $client = null): User
    {
        return User::factory()->create(['name' => 'Acceso '.$role, 'role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company?->id, 'client_id' => $client?->id, 'password' => self::$testPasswordHash ??= Hash::make('Testing-password-12345')]);
    }

    private function customer(User $owner, string $name): Client
    {
        return Client::create(['name' => $name, 'document' => 'DOC-'.Str::uuid(), 'phone' => '04121234567', 'active' => true, 'customer_kind' => 'person', 'user_id' => $owner->id, 'dealership_id' => $owner->dealership_id, 'notes' => 'NOTA INTERNA RESERVADA']);
    }

    private function sale(User $owner, Client $client, string $amount = '100.00'): Order
    {
        return app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta '.$client->name, 'items' => [['description' => 'Producto del comprador', 'quantity' => 1, 'unit_price' => $amount]], 'initial_amount' => '20.00', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString(), 'notes' => 'OBSERVACIÓN INTERNA']);
    }

    private function receive(User $owner, Client $client, string $amount): Receipt
    {
        $method = PaymentMethod::firstOrCreate(['name' => 'Efectivo', 'dealership_id' => $client->dealership_id], ['active' => true]);

        $receipt = app(ReceiptService::class)->receive($owner, $client->id, ['payment_date' => today()->toDateString(), 'token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id]);
        $this->approvePendingReceipts();

        return $receipt->fresh();
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $hq = Dealership::factory()->create(['name' => 'Matriz A', 'organization_type' => 'head_office']);
        $a = Dealership::factory()->create(['name' => 'Sucursal A', 'parent_id' => $hq->id]);
        $b = Dealership::factory()->create(['name' => 'Sucursal B', 'parent_id' => $hq->id]);
        $otherHq = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreign = Dealership::factory()->create(['name' => 'SUCURSAL RESERVADA', 'parent_id' => $otherHq->id]);
        $ownerA = $this->actor('dealership_admin', $a);
        $ownerB = $this->actor('dealership_admin', $b);
        $ownerForeign = $this->actor('dealership_admin', $foreign);
        $clientA = $this->customer($ownerA, 'CLIENTE SECRETO A');
        $neighbor = $this->customer($ownerA, 'CLIENTE SECRETO VECINO');
        $clientB = $this->customer($ownerB, 'CLIENTE SECRETO B');
        $clientForeign = $this->customer($ownerForeign, 'CLIENTE DE OTRA MATRIZ');
        $orderA = $this->sale($ownerA, $clientA);
        $orderNeighbor = $this->sale($ownerA, $neighbor, '50.00');
        $orderB = $this->sale($ownerB, $clientB, '200.00');
        $this->sale($ownerForeign, $clientForeign, '900.00');
        $receiptA = $this->receive($ownerA, $clientA, '70.00');
        $receiptNeighbor = $this->receive($ownerA, $neighbor, '10.00');
        $receiptB = $this->receive($ownerB, $clientB, '20.00');
        app(ReceiptService::class)->apply($ownerA, $receiptA, $orderA->id, ['token' => (string) Str::uuid(), 'amount' => '30.00']);

        return compact('hq', 'a', 'b', 'otherHq', 'foreign', 'ownerA', 'ownerB', 'clientA', 'neighbor', 'orderA', 'orderNeighbor', 'orderB', 'receiptA', 'receiptNeighbor', 'receiptB');
    }

    public function test_head_office_uses_contact_concessionaires_and_never_exposes_legacy_retail_records(): void
    {
        $data = $this->portfolio();
        $actor = $this->actor('head_office', $data['hq']);
        $contact = Client::factory()->concessionaire()->for($data['ownerA'], 'owner')->create(['dealership_id' => $data['hq']->id, 'name' => 'Concesionario de la matriz']);
        $this->actingAs($actor)->get(route('home'))->assertOk()->assertSee('Tu espacio de trabajo')
            ->assertViewHas('modules', fn ($modules) => array_keys($modules) === ['head-office', 'products', 'payments', 'reports', 'settings'] && isset($modules['head-office']['description']))
            ->assertSee('Registra tus concesionarios')->assertDontSee('Programa ventas, organiza cuotas');
        $response = $this->get(route('head-office.overview', ['dealership_id' => $data['foreign']->id, 'client_id' => $data['clientA']->id]))->assertOk();
        $groups = $response->viewData('groups');
        $this->assertSame(1, $groups['dealership']['total']);
        $this->assertSame(0, $groups['person']['total']);
        foreach (['sold_month_cents', 'debt_cents', 'received_month_cents', 'credit_cents'] as $metric) {
            $this->assertSame(0, $groups['dealership'][$metric]);
        }
        $response->assertSee($contact->name)->assertDontSee('Sucursal A')->assertDontSee('CLIENTE SECRETO')->assertDontSee('SUCURSAL RESERVADA')->assertDontSee('NOTA INTERNA');
        $this->get(route('clients.index'))->assertOk()->assertSee($contact->name)->assertDontSee('CLIENTE SECRETO');
        $this->get(route('users'))->assertOk();
        foreach (['workspace.customers', 'workspace.sales', 'orders.index', 'audit', 'orders.client-options'] as $route) {
            $this->get(route($route, ['export' => 'csv']))->assertForbidden();
        }
        $this->get(route('clients.show', $data['clientA']))->assertForbidden();
        $this->get(route('orders.show', $data['orderA']))->assertForbidden();
        $this->post(route('receipts.apply', $data['receiptA']), [])->assertForbidden();
        $this->assertFalse(Gate::forUser($actor)->allows('view', $data['clientA']));
        $this->assertSame([$contact->id], Client::visibleTo($actor)->pluck('id')->all());
    }

    public function test_customer_portal_is_read_only_and_rejects_other_customers_in_the_same_branch(): void
    {
        $data = $this->portfolio();
        $actor = $this->actor('customer', $data['a'], $data['clientA']);
        $this->actingAs($actor)->get(route('home'))->assertRedirect(route('portal.account'));
        $response = $this->get(route('portal.account', ['client_id' => $data['neighbor']->id, 'dealership_id' => $data['b']->id]))->assertOk();
        $response->assertSee('CLIENTE SECRETO A')->assertSee('$70.00')->assertSee('$40.00')->assertDontSee('CLIENTE SECRETO VECINO')->assertDontSee('CLIENTE SECRETO B')->assertDontSee('Registrar pago')->assertDontSee('NOTA INTERNA');
        $this->assertSame([$data['orderA']->id], $response->viewData('orders')->modelKeys());
        $this->assertSame([$data['clientA']->id], Client::visibleTo($actor)->pluck('id')->all());
        $this->assertSame([$data['orderA']->id], Order::visibleTo($actor)->pluck('id')->all());
        $this->assertSame([$data['receiptA']->id], Receipt::visibleTo($actor)->pluck('id')->all());
        $this->get(route('portal.order', $data['orderA']))->assertOk()->assertSee('Mis compromisos de pago')->assertDontSee('OBSERVACIÓN INTERNA')->assertDontSee('payment-dialog');
        $this->get(route('portal.receipt', $data['receiptA']))->assertOk()->assertSee('Aplicaciones de mi pago');
        foreach ([$data['orderNeighbor'], $data['orderB']] as $order) {
            $this->get(route('portal.order', $order))->assertNotFound();
        }
        foreach ([$data['receiptNeighbor'], $data['receiptB']] as $receipt) {
            $this->get(route('portal.receipt', $receipt))->assertNotFound();
        }
        foreach (['orders.index', 'receipts.index', 'clients.index', 'products.index', 'users', 'audit', 'reports', 'workspace.settings', 'orders.client-options'] as $route) {
            $this->get(route($route, ['export' => 'csv']))->assertForbidden();
        }
        $this->post(route('orders.pay', $data['orderA']), ['amount' => '100'])->assertForbidden();
        $this->post(route('receipts.store'), [])->assertForbidden();
        $this->post(route('users.store'), [])->assertForbidden();
        $this->assertFalse(Gate::forUser($actor)->allows('update', $data['orderA']));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $data['receiptA']));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $data['clientA']));
        $this->assertSame(0, Product::visibleTo($actor)->count());
        $this->assertDatabaseCount('receipts', 3);
    }

    public function test_concessionaire_manages_own_clients_and_system_administrator_sees_all(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['ownerA'])->get(route('clients.index'))->assertOk()->assertSee('CLIENTE SECRETO A')->assertSee('CLIENTE SECRETO VECINO')->assertDontSee('CLIENTE SECRETO B');
        $this->get(route('workspace.customers'))->assertOk()->assertViewHas('groups', fn ($groups) => count($groups) === 1 && $groups['person']['total'] === 2);
        $this->get(route('orders.show', $data['orderA']))->assertOk();
        $this->get(route('orders.show', $data['orderB']))->assertForbidden();
        $this->get(route('dealerships.index'))->assertForbidden();
        $this->get(route('head-office.overview'))->assertForbidden();
        $this->post(route('dealership-context'), ['dealership_id' => $data['b']->id])->assertForbidden();

        $this->actingAs($this->actor('admin'))->get(route('orders.show', $data['orderB']))->assertOk();
        $this->get(route('clients.index'))->assertOk()->assertSee('CLIENTE DE OTRA MATRIZ');
        $this->get(route('users.create'))->assertOk()->assertViewHas('roles', fn ($roles) => $roles->count() === 4 && ! $roles->contains('name', 'user'));
    }

    public function test_account_creation_requires_the_right_organization_and_exact_client_link(): void
    {
        $data = $this->portfolio();
        $admin = $this->actor('admin');
        $role = fn (string $name): int => Role::where('name', $name)->value('id');
        $values = ['name' => 'Cuenta de cliente', 'username' => 'portal_client', 'email' => 'portal@example.test', 'password' => 'Testing-password-12345', 'role_id' => $role('customer'), 'dealership_id' => $data['a']->id, 'active' => 1];

        $this->actingAs($admin)->post(route('users.store'), $values)->assertSessionHasErrors('client_id');
        $this->post(route('users.store'), $values + ['client_id' => $data['orderB']->client_id])->assertSessionHasErrors('client_id');
        $this->post(route('users.store'), $values + ['client_id' => $data['clientA']->id])->assertSessionHasNoErrors();
        $created = User::where('username', 'portal_client')->firstOrFail();
        $this->assertSame($data['clientA']->id, $created->client_id);
        $this->assertTrue(Hash::check('Testing-password-12345', $created->password));
        $this->post(route('users.store'), array_replace($values, ['username' => 'duplicate_link', 'email' => 'duplicate@example.test', 'client_id' => $data['clientA']->id]))->assertSessionHasErrors('client_id');
        $this->post(route('users.store'), array_replace($values, ['username' => 'wrong_matriz', 'email' => 'wrong@example.test', 'role_id' => $role('head_office')]))->assertSessionHasErrors('dealership_id');
        $this->post(route('users.store'), array_replace($values, ['username' => 'head_office', 'email' => 'head@example.test', 'role_id' => $role('head_office'), 'dealership_id' => $data['hq']->id]))->assertSessionHasNoErrors();
        $this->actingAs($data['ownerA'])->post(route('users.store'), array_replace($values, ['username' => 'escalation', 'email' => 'escalation@example.test', 'role_id' => $role('admin')]))->assertForbidden();
        $this->post(route('users.store'), array_replace($values, ['username' => 'cross_link', 'email' => 'cross@example.test', 'dealership_id' => $data['b']->id, 'client_id' => $data['orderB']->client_id]))->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'escalation']);
        $this->assertDatabaseMissing('users', ['username' => 'cross_link']);
    }

    public function test_deactivating_client_or_parent_organization_revokes_access_and_unlinked_customer_cannot_log_in(): void
    {
        $data = $this->portfolio();
        $actor = $this->actor('customer', $data['a'], $data['clientA']);
        $this->post(route('login'), ['email' => $actor->email, 'password' => 'Testing-password-12345'])->assertRedirect(route('home'));
        $this->get(route('portal.account'))->assertOk();
        $data['clientA']->update(['active' => false]);
        $this->get(route('portal.account'))->assertRedirect(route('login'));
        $this->assertGuest();
        $unlinked = $this->actor('customer', $data['a']);
        $this->post(route('login'), ['email' => $unlinked->email, 'password' => 'Testing-password-12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($data['ownerA'])->get(route('clients.index'))->assertOk();
        $data['hq']->update(['active' => false]);
        $this->get(route('clients.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_organization_hierarchy_rejects_cycles_and_concessionaires_as_parents(): void
    {
        $data = $this->portfolio();
        $this->actingAs($this->actor('admin'))->post(route('dealerships.store'), ['name' => 'Sucursal nueva', 'code' => 'NEW', 'active' => 1, 'organization_type' => 'concessionaire', 'parent_id' => $data['a']->id])->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('dealerships', ['code' => 'NEW']);
        $this->put(route('dealerships.update', $data['a']), ['name' => 'Sucursal A', 'code' => $data['a']->code, 'active' => 1, 'organization_type' => 'concessionaire', 'parent_id' => $data['a']->id])->assertSessionHasErrors('parent_id');
        $this->post(route('dealerships.store'), ['name' => 'Sucursal nueva', 'code' => 'NEW', 'active' => 1, 'organization_type' => 'concessionaire', 'parent_id' => $data['hq']->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dealerships', ['code' => 'NEW', 'parent_id' => $data['hq']->id]);
    }
}
