<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Services\ReportService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class DealershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role, ?Dealership $dealer = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->firstOrFail()->id, 'dealership_id' => $dealer?->id]);
    }

    private function sale(User $owner, string $name): Order
    {
        $client = Client::create(['user_id' => $owner->id, 'name' => $name, 'document' => (string) Str::uuid(), 'phone' => '04121234567']);
        $method = PaymentMethod::firstOrCreate(['dealership_id' => $owner->dealership_id, 'name' => 'Efectivo']);

        $order = app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => $name, 'items' => [['description' => 'Vehículo', 'quantity' => 1, 'unit_price' => '1000.00']], 'initial_amount' => '100.00', 'payment_method_id' => $method->id, 'installment_count' => 9, 'frequency' => 'monthly', 'first_due' => '2025-01-01']);
        app(SalesService::class)->pay($owner, $order->id, ['token' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method_id' => $method->id, 'installment_id' => $order->installments->firstWhere('number', 0)->id]);

        return $order->fresh();
    }

    private function userData(User $user): array
    {
        return ['name' => $user->name, 'username' => $user->username ?? 'updated_user', 'email' => $user->email, 'role_id' => $user->role_id, 'active' => 1, 'dealership_id' => $user->dealership_id];
    }

    public function test_policy_matrix_distinguishes_global_local_and_own_operations(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $owner = $this->user('user', $a);
        $order = $this->sale($owner, 'Cliente A');
        $cases = [[$this->user('admin'), true], [$this->user('dealership_admin', $a), true], [$owner, true], [$this->user('user', $a), false], [$this->user('dealership_admin', $b), false], [$this->user('user', $b), false]];
        foreach ($cases as [$actor,$allowed]) {
            foreach (['view', 'update'] as $ability) {
                $this->assertSame($allowed, Gate::forUser($actor)->allows($ability, $order));
                $this->assertSame($allowed, Gate::forUser($actor)->allows($ability, $order->client));
            }
        }
    }

    public function test_local_manager_cannot_read_or_modify_another_dealership(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $manager = $this->user('dealership_admin', $a);
        $own = $this->sale($this->user('user', $a), 'Cliente permitido');
        $foreign = $this->sale($this->user('user', $b), 'CLIENTE EXCLUSIVO B');
        $method = $foreign->payments->first()->method;
        $this->actingAs($manager)->get(route('orders.show', $own))->assertOk();
        $this->get(route('orders.show', $foreign))->assertForbidden();
        $this->get(route('clients.show', $foreign->client))->assertForbidden();
        $this->get(route('clients.edit', $foreign->client))->assertForbidden();
        $this->post(route('orders.pay', $foreign), [])->assertForbidden();
        $this->post(route('payments.void', $foreign->payments->first()), ['reason' => 'Prueba de acceso ajeno'])->assertForbidden();
        $this->get(route('payment-methods.edit', $method))->assertForbidden();
        $this->put(route('payment-methods.update', $method), ['name' => 'Cambio', 'active' => 0])->assertForbidden();
        $this->delete(route('payment-methods.destroy', $method))->assertForbidden();
        $this->get(route('dealerships.index'))->assertForbidden();
        $this->post(route('dealership-context'), ['dealership_id' => $b->id])->assertForbidden();
        foreach (['orders.index', 'clients.index', 'collections', 'dashboard'] as $route) {
            $this->get(route($route, ['dealership_id' => $b->id, 'user_id' => $foreign->user_id]))->assertOk()->assertDontSee('CLIENTE EXCLUSIVO B');
        }
    }

    public function test_every_report_export_and_print_view_is_tenant_scoped(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $manager = $this->user('dealership_admin', $a);
        $own = $this->sale($this->user('user', $a), 'CLIENTE LOCAL A');
        $foreign = $this->sale($this->user('user', $b), 'CLIENTE EXCLUSIVO B');
        $this->actingAs($manager);
        foreach (array_keys(ReportService::TYPES) as $type) {
            $params = ['type' => $type, 'dealership_id' => $b->id, 'user_id' => $foreign->user_id];
            $response = $this->get(route('reports', $params + ['export' => 'csv']));
            $response->assertOk();
            $this->assertStringNotContainsString('CLIENTE EXCLUSIVO B', $response->streamedContent());
            $this->get(route('reports', ['type' => $type, 'print' => 1]))->assertOk()->assertDontSee('CLIENTE EXCLUSIVO B');
        }
    }

    public function test_global_context_filters_all_modules_and_restores_consolidated_view(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $own = $this->sale($this->user('user', $a), 'CLIENTE LOCAL A');
        $other = $this->sale($this->user('user', $b), 'CLIENTE EXCLUSIVO B');
        $this->actingAs($this->user('admin'))->get(route('orders.index'))->assertSee('CLIENTE LOCAL A')->assertSee('CLIENTE EXCLUSIVO B');
        $this->post(route('dealership-context'), ['dealership_id' => $a->id])->assertRedirect(route('dashboard'));
        foreach (['orders.index', 'clients.index', 'collections', 'dashboard'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('CLIENTE LOCAL A')->assertDontSee('CLIENTE EXCLUSIVO B');
        }
        $response = $this->get(route('reports', ['export' => 'csv']));
        $this->assertStringContainsString('CLIENTE LOCAL A', $response->streamedContent());
        $this->assertStringNotContainsString('CLIENTE EXCLUSIVO B', $response->streamedContent());
        $this->get(route('users'))->assertSee($own->owner->email)->assertDontSee($other->owner->email);
        $this->get(route('clients.index', ['dealership_id' => $b->id]))->assertOk()->assertSee('CLIENTE EXCLUSIVO B')->assertDontSee('CLIENTE LOCAL A');
        $this->get(route('clients.index', ['dealership_id' => '']))->assertOk()->assertSee('CLIENTE LOCAL A')->assertSee('CLIENTE EXCLUSIVO B');
        $otherExport = $this->get(route('reports', ['export' => 'csv', 'dealership_id' => $b->id]));
        $this->assertStringContainsString('CLIENTE EXCLUSIVO B', $otherExport->streamedContent());
        $this->assertStringNotContainsString('CLIENTE LOCAL A', $otherExport->streamedContent());
        $allExport = $this->get(route('reports', ['export' => 'csv', 'dealership_id' => '']));
        $this->assertStringContainsString('CLIENTE LOCAL A', $allExport->streamedContent());
        $this->assertStringContainsString('CLIENTE EXCLUSIVO B', $allExport->streamedContent());
        $this->get(route('clients.index'))->assertSee('CLIENTE LOCAL A')->assertDontSee('CLIENTE EXCLUSIVO B');
        $this->postJson(route('dealership-context'), ['dealership_id' => $b->id])->assertOk()->assertJsonPath('dealership_id', $b->id);
        $this->post(route('dealership-context'), ['dealership_id' => ''])->assertRedirect();
        $this->get(route('orders.index'))->assertSee('CLIENTE EXCLUSIVO B');
    }

    public function test_local_user_management_cannot_escalate_roles_or_move_users(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $manager = $this->user('dealership_admin', $a);
        $other = $this->user('user', $b);
        $global = $this->user('admin');
        $this->actingAs($manager)->get(route('users'))->assertOk()->assertDontSee($other->email)->assertDontSee($global->email);
        $this->get(route('users'))->assertSee(route('users.create'), false)->assertDontSee('name="password"', false);
        $this->get(route('users.create'))->assertOk();
        $this->get(route('users.edit', $manager))->assertOk();
        $this->get(route('users.edit', $other))->assertForbidden();
        $this->get(route('users.edit', $global))->assertForbidden();
        $data = ['name' => 'Nuevo asesor', 'username' => 'new_advisor', 'email' => 'new@example.test', 'password' => 'secure-password-123', 'role_id' => Role::where('name', 'user')->first()->id, 'active' => 1, 'dealership_id' => $b->id];
        $this->post(route('users.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'dealership_id' => $a->id]);
        $data['email'] = 'attack@example.test';
        $data['username'] = 'attack';
        $data['role_id'] = $global->role_id;
        $this->post(route('users.store'), $data)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'attack@example.test']);
        $this->put(route('users.update', $other), $this->userData($other))->assertForbidden();
        $this->put(route('users.update', $global), $this->userData($global))->assertForbidden();
        $this->put(route('users.update', $manager), array_replace($this->userData($manager), ['role_id' => Role::where('name', 'user')->first()->id]))->assertSessionHasErrors('role_id');
    }

    public function test_client_owner_and_dealership_cannot_be_forged_or_cross_assigned(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $owner = $this->user('user', $a);
        $foreign = $this->user('user', $b);
        $data = ['name' => 'Cliente seguro', 'document' => 'SAFE', 'phone' => '0412', 'active' => 1, 'user_id' => $foreign->id, 'dealership_id' => $b->id];
        $this->actingAs($owner)->post(route('clients.store'), $data)->assertSessionHasNoErrors();
        $client = Client::where('document', 'SAFE')->firstOrFail();
        $this->assertSame($a->id, $client->dealership_id);
        $this->assertSame($owner->id, $client->user_id);
        $this->actingAs($this->user('dealership_admin', $a))->put(route('clients.update', $client), $data)->assertForbidden();
        $foreignOrder = $this->sale($foreign, 'Cliente ajeno');
        $this->post(route('clients.store'), array_replace($data, ['document' => $foreignOrder->client->document]))->assertForbidden();
        $this->actingAs($this->user('admin'))->put(route('clients.update', $client), $data)->assertSessionHasErrors('user_id');
        $this->assertSame($a->id, $client->fresh()->dealership_id);
    }

    public function test_payment_methods_and_audit_are_isolated(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $admin = $this->user('admin');
        $aOrder = $this->sale($this->user('user', $a), 'Cliente A');
        $bOrder = $this->sale($this->user('user', $b), 'Cliente B');
        Audit::log($admin->id, 'LOCAL_EVENT', 'order', $aOrder->id);
        Audit::log($admin->id, 'FOREIGN_EVENT', 'order', $bOrder->id);
        $manager = $this->user('dealership_admin', $a);
        $this->actingAs($manager)->get(route('audit'))->assertOk()->assertSee('LOCAL_EVENT')->assertDontSee('FOREIGN_EVENT');
        DB::table('dealership_settings')->insert(['dealership_id' => $a->id, 'key' => 'business_name', 'value' => 'Nombre anterior']);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('businessName', $a->name);
        $this->post(route('payment-methods.store'), ['name' => 'Método exclusivo A', 'active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->user('dealership_admin', $b))->get(route('payment-methods.index'))->assertOk()->assertDontSee('Método exclusivo A');
        $this->post(route('payment-methods.store'), ['name' => 'Método exclusivo A', 'active' => 1])->assertSessionHasNoErrors();
    }

    public function test_wrong_dealership_payment_method_is_rejected_without_changing_balances(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $owner = $this->user('user', $a);
        $order = $this->sale($owner, 'Cliente A');
        $method = PaymentMethod::create(['dealership_id' => $b->id, 'name' => 'Ajeno']);
        $this->actingAs($owner)->post(route('orders.pay', $order), ['token' => (string) Str::uuid(), 'amount' => '50.00', 'payment_method_id' => $method->id])->assertSessionHasErrors('amount');
        $this->assertSame(90000, $order->fresh()->balance_cents);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_local_manager_can_receive_and_void_payments_with_audit(): void
    {
        $dealer = Dealership::factory()->create();
        $owner = $this->user('user', $dealer);
        $order = $this->sale($owner, 'Cliente A');
        $manager = $this->user('dealership_admin', $dealer);
        $payment = app(SalesService::class)->pay($manager, $order->id, ['token' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method_id' => $order->payments->first()->payment_method_id]);
        $this->assertSame($dealer->id, $payment->dealership_id);
        $this->assertSame($manager->id, $payment->recorded_by);
        $this->actingAs($manager)->post(route('payments.void', $payment), ['reason' => 'Pago duplicado por error'])->assertSessionHasNoErrors();
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame(90000, $order->fresh()->balance_cents);
        $this->assertDatabaseHas('audit_logs', ['action' => 'void', 'entity_id' => $payment->id, 'dealership_id' => $dealer->id]);
    }

    public function test_inactive_dealership_blocks_login_existing_sessions_and_new_payments(): void
    {
        $dealer = Dealership::factory()->create();
        $owner = $this->user('user', $dealer);
        $owner->update(['password' => 'secure-password-123']);
        $order = $this->sale($owner, 'Cliente A');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $dealer->update(['active' => false]);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login'), ['email' => $owner->email, 'password' => 'secure-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->flushSession();
        $this->actingAs($this->user('admin'))->get(route('orders.show', $order))->assertOk();
        $this->post(route('orders.pay', $order), ['token' => (string) Str::uuid(), 'amount' => '50', 'payment_method_id' => $order->payments->first()->payment_method_id])->assertSessionHasErrors('dealership_id');
    }

    public function test_global_admin_creates_and_edits_dealership_and_keeps_existing_data(): void
    {
        $this->actingAs($this->user('admin'))->get(route('dealerships.index'))->assertOk()->assertSee('Concesionario principal');
        $this->get(route('dealerships.create'))->assertOk();
        $this->post(route('dealerships.store'), ['name' => 'Bera Caracas', 'code' => 'BERA-CCS', 'active' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $dealer = Dealership::where('code', 'BERA-CCS')->firstOrFail();
        $this->assertSame(6, PaymentMethod::where('dealership_id', $dealer->id)->count());
        $this->get(route('dealerships.edit', $dealer))->assertOk();
        $this->put(route('dealerships.update', $dealer), ['name' => 'Bera Caracas Centro', 'code' => 'BERA-CCS', 'active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($dealer->fresh()->active);
        $this->assertDatabaseHas('dealerships', ['code' => 'PRINCIPAL', 'active' => true]);
    }

    public function test_user_with_an_existing_portfolio_cannot_be_moved_to_another_dealership(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $owner = $this->user('user', $a);
        $order = $this->sale($owner, 'Cliente A');
        $this->actingAs($this->user('admin'))->put(route('users.update', $owner), array_replace($this->userData($owner), ['dealership_id' => $b->id]))->assertSessionHasErrors('dealership_id');
        $this->assertSame($a->id, $owner->fresh()->dealership_id);
        $this->assertSame($a->id, $order->fresh()->dealership_id);
    }

    public function test_global_admin_can_edit_a_user_with_operations_without_changing_dealership(): void
    {
        $dealer = Dealership::factory()->create();
        $owner = $this->user('user', $dealer);
        $this->sale($owner, 'Cliente existente');
        $this->actingAs($this->user('admin'))->put(route('users.update', $owner), array_replace($this->userData($owner), ['dealership_id' => (string) $dealer->id, 'name' => 'Asesor actualizado']))->assertSessionHasNoErrors();
        $this->assertSame('Asesor actualizado', $owner->fresh()->name);
    }

    public function test_global_admin_keeps_access_but_form_excludes_unassigned_global_users(): void
    {
        $global = $this->user('admin');
        $dealer = Dealership::factory()->create();
        $this->actingAs($global)->post(route('dealership-context'), ['dealership_id' => $dealer->id]);
        $this->get(route('clients.create'))->assertOk()->assertViewHas('users', fn ($users) => ! $users->contains('id', $global->id));
        $this->post(route('clients.store'), ['name' => 'Cliente local', 'document' => 'GLOBAL-OWNER', 'phone' => '0412', 'active' => 1, 'user_id' => $global->id, 'dealership_id' => $dealer->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', ['document' => 'GLOBAL-OWNER', 'dealership_id' => $dealer->id, 'user_id' => $global->id]);
    }
}
