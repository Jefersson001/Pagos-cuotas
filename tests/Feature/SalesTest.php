<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SalesService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actor(bool $admin = false): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $admin ? 'admin' : 'user')->first()->id]);
    }

    private function sale(User $u, array $extra = []): Order
    {
        $c = Client::create(['user_id' => $u->id, 'name' => 'Cliente '.$u->id, 'document' => (string) Str::uuid(), 'phone' => '04120000000']);

        $order = app(SalesService::class)->create($u, array_replace(['token' => (string) Str::uuid(), 'client_id' => $c->id, 'description' => 'Equipo', 'items' => [['description' => 'Artículo', 'quantity' => 1, 'unit_price' => '500.00']], 'initial_amount' => '100.00', 'payment_method_id' => PaymentMethod::first()->id, 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->subMonthNoOverflow()->toDateString()], $extra));
        if (Money::cents($order->initial_amount) > 0) {
            $this->pay($u, $order, $order->initial_amount, ['installment_id' => $order->installments->firstWhere('number', 0)->id]);
        }

        return $order->fresh();
    }

    private function pay(User $u, Order $o, string $amount, array $extra = [])
    {
        return app(SalesService::class)->pay($u, $o->id, array_replace(['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => PaymentMethod::first()->id], $extra));
    }

    public function test_user_cannot_read_pay_edit_or_export_another_users_records(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $o = $this->sale($a);
        $this->actingAs($b)->get(route('orders.show', $o))->assertForbidden();
        $this->get(route('clients.show', $o->client))->assertForbidden();
        $this->get(route('clients.edit', $o->client))->assertForbidden();
        $this->post(route('orders.pay', $o), [])->assertForbidden();
        $this->get(route('orders.index'))->assertDontSee('Equipo');
        $this->get(route('reports', ['export' => 'csv', 'user_id' => $a->id]))->assertStreamedContent("\xEF\xBB\xBFConcesionario,Pedido,Cliente,Responsable,Total,Abonado,Saldo,Estado\n");
        $this->get(route('users'))->assertForbidden();
    }

    public function test_admin_sees_all_and_normal_user_only_sees_own(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $o = $this->sale($a);
        $p = $this->sale($b);
        $this->actingAs($this->actor(true))->get(route('orders.show', $o))->assertOk();
        $this->get(route('orders.show', $p))->assertOk();
        $this->get(route('orders.index'))->assertSee($o->client->name)->assertSee($p->client->name);
    }

    public function test_partial_payments_advance_and_finish_order(): void
    {
        $u = $this->actor();
        $o = $this->sale($u);
        $this->pay($u, $o, '25.00');
        $this->pay($u, $o, '35.00');
        $this->assertSame(4000, $o->fresh()->installments->firstWhere('number', 1)->remaining_cents);
        $this->pay($u, $o, '190.00');
        $this->assertSame(0, $o->fresh()->installments->firstWhere('number', 2)->remaining_cents);
        $this->pay($u, $o, '150.00');
        $this->assertSame('Pagado', $o->fresh()->status);
        $this->assertSame(0, $o->fresh()->balance_cents);
    }

    public function test_rounding_is_exact_and_months_do_not_overflow(): void
    {
        $u = $this->actor();
        $o = $this->sale($u, ['initial_amount' => '0', 'installment_count' => 3, 'first_due' => '2026-01-31']);
        $this->assertSame(['166.67', '166.67', '166.66'], $o->installments->pluck('amount')->all());
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $o->installments->map(fn ($i) => $i->due_date->toDateString())->all());
    }

    public function test_overdue_is_derived_and_overpayment_rejected(): void
    {
        $u = $this->actor();
        $o = $this->sale($u);
        $this->assertSame('Atrasado', $o->status);
        $this->expectException(ValidationException::class);
        $this->pay($u, $o, '400.01');
    }

    public function test_idempotent_payments_and_orders(): void
    {
        $u = $this->actor();
        $o = $this->sale($u);
        $token = (string) Str::uuid();
        $first = $this->pay($u, $o, '20.00', ['token' => $token]);
        $second = $this->pay($u, $o, '20.00', ['token' => $token]);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(12000, $o->fresh()->paid_cents);
    }

    public function test_void_restores_installments_and_requires_admin(): void
    {
        $u = $this->actor();
        $o = $this->sale($u);
        $p = $this->pay($u, $o, '100.00');
        $this->actingAs($u)->post(route('payments.void', $p), ['reason' => 'Registrado por error'])->assertForbidden();
        app(SalesService::class)->void($this->actor(true), $p, 'Registrado por error');
        $this->assertSame(40000, $o->fresh()->balance_cents);
        $this->assertSame(10000, $o->fresh()->installments->firstWhere('number', 1)->remaining_cents);
        $this->assertDatabaseHas('audit_logs', ['action' => 'void', 'entity_id' => $p->id]);
    }

    public function test_void_initial_can_be_repaid_without_breaking_schedule(): void
    {
        $u = $this->actor();
        $o = $this->sale($u);
        app(SalesService::class)->void($this->actor(true), $o->payments->first(), 'Inicial incorrecta');
        $this->pay($u, $o, '100.00', ['payment_source' => 'credit']);
        $this->pay($u, $o, '400.00');
        $this->assertSame('Pagado', $o->fresh()->status);
        $this->assertSame(0, $o->fresh()->installments->sum('remaining_cents'));
    }

    public function test_custom_schedule_must_equal_financed_balance(): void
    {
        $u = $this->actor();
        $this->expectException(ValidationException::class);
        $this->sale($u, ['frequency' => 'custom', 'installment_count' => 2, 'custom' => [['due_date' => '2026-11-01', 'amount' => '100'], ['due_date' => '2026-11-10', 'amount' => '200']]]);
    }

    public function test_specific_installment_must_belong_to_order(): void
    {
        $u = $this->actor();
        $o = $this->sale($u);
        $other = $this->sale($u);
        $this->expectException(ValidationException::class);
        $this->pay($u, $o, '10.00', ['installment_id' => $other->installments->first()->id]);
    }

    public function test_client_can_have_multiple_independent_orders(): void
    {
        $u = $this->actor();
        $o = $this->sale($u);
        $other = $this->sale($u, ['client_id' => $o->client_id]);
        $this->pay($u, $o, '400');
        $this->assertSame('Pagado', $o->fresh()->status);
        $this->assertSame(40000, $other->fresh()->balance_cents);
        $this->assertSame(2, $o->client->orders()->count());
    }

    public function test_owner_cannot_be_forged_in_browser(): void
    {
        $u = $this->actor();
        $other = $this->actor();
        $this->actingAs($u)->post(route('clients.store'), ['name' => 'Propio', 'document' => '123', 'phone' => '123', 'user_id' => $other->id, 'active' => 1])->assertRedirect();
        $this->assertDatabaseHas('clients', ['name' => 'Propio', 'user_id' => $u->id]);
    }

    public function test_all_screens_render_and_guest_is_redirected(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $u = $this->actor(true);
        $o = $this->sale($u);
        $this->actingAs($u);
        foreach (['dashboard', 'clients.index', 'clients.create', 'orders.index', 'orders.create', 'collections', 'reports', 'users', 'audit'] as $route) {
            $this->get(route($route))->assertOk();
        }$this->get(route('clients.show', $o->client))->assertOk();
        $this->get(route('clients.edit', $o->client))->assertOk();
        $this->get('/settings')->assertNotFound();
        $this->post('/settings', ['business_name' => 'Nombre anterior'])->assertNotFound();
        $this->get(route('orders.show', $o))->assertOk();
    }

    public function test_login_and_deactivated_session(): void
    {
        $u = $this->actor();
        $u->update(['password' => 'secure-password-123']);
        $this->post('/login', ['email' => $u->email, 'password' => 'secure-password-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $u->update(['active' => false]);
        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_all_report_exports_are_scoped_and_payment_dates_are_used(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $o = $this->sale($a);
        $other = $this->sale($b);
        $this->actingAs($a);
        foreach (array_keys(ReportService::TYPES) as $type) {
            $this->get(route('reports', ['type' => $type]))->assertOk()->assertDontSee($other->client->name);
            $response = $this->get(route('reports', ['type' => $type, 'export' => 'csv', 'user_id' => $b->id]));
            $response->assertOk();
            $this->assertStringNotContainsString($other->client->name, $response->streamedContent());
        }$o->update(['created_at' => today()->subYear()]);
        $this->get(route('reports', ['type' => 'receipts', 'from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertSee($o->client->name);
    }

    public function test_failed_custom_sale_rolls_back_all_records(): void
    {
        $u = $this->actor();
        try {
            $this->sale($u, ['frequency' => 'custom', 'installment_count' => 1, 'custom' => [['due_date' => '2026-12-01', 'amount' => '399.99']]]);
            $this->fail('Debe rechazar el cronograma');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('installment_schedules', 0);
        }
    }

    public function test_admin_reassignment_moves_orders_and_payments_together(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $o = $this->sale($a);
        $c = $o->client;
        $this->actingAs($this->actor(true))->put(route('clients.update', $c), ['name' => $c->name, 'document' => $c->document, 'phone' => $c->phone, 'active' => 1, 'user_id' => $b->id])->assertRedirect();
        $this->assertSame($b->id, $o->fresh()->user_id);
        $this->assertSame($b->id, $o->payments()->first()->user_id);
        $this->actingAs($a)->get(route('orders.show', $o))->assertForbidden();
    }
}
