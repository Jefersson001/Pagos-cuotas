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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role = 'user', ?int $dealer = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $dealer ?? Dealership::first()->id]);
    }

    private function client(User $user, string $name): Client
    {
        return Client::create(['user_id' => $user->id, 'dealership_id' => $user->dealership_id, 'name' => $name, 'document' => (string) Str::uuid(), 'phone' => '0412']);
    }

    private function order(User $user, Client $client, string $initial = '0', ?string $due = null): Order
    {
        return app(SalesService::class)->create($user, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Moto', 'items' => [['description' => 'Moto', 'quantity' => 1, 'unit_price' => '400.00']], 'initial_amount' => $initial, 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => $due ?? today()->addMonth()->toDateString()]);
    }

    private function receipt(User $user, Client $client, string $amount): Receipt
    {
        $method = PaymentMethod::firstOrCreate(['dealership_id' => $client->dealership_id, 'name' => 'Caja resumen']);

        return app(ReceiptService::class)->receive($user, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id]);
    }

    public function test_client_priority_and_totals_include_debt_credit_and_empty_clients(): void
    {
        $user = $this->user();
        $empty = $this->client($user, 'A Sin movimientos');
        $history = $this->client($user, 'B Cuenta pagada');
        $credit = $this->client($user, 'C Saldo a favor');
        $debt = $this->client($user, 'D Deuda');
        $applicable = $this->client($user, 'E Saldo por aplicar');
        $overdue = $this->client($user, 'F Vencido');
        $this->order($user, $debt);
        $this->order($user, $applicable);
        $this->order($user, $overdue, '0', today()->subDay()->toDateString());
        $paid = $this->order($user, $history);
        $receipt = $this->receipt($user, $history, '400.00');
        app(ReceiptService::class)->apply($user, $receipt, $paid->id, ['token' => (string) Str::uuid(), 'amount' => '400.00']);
        $this->receipt($user, $credit, '200.00');
        $this->receipt($user, $applicable, '100.00');
        Product::factory()->create(['dealership_id' => $user->dealership_id]);
        Product::factory()->create(['dealership_id' => $user->dealership_id, 'active' => false]);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $summary = $response->viewData('summary');
        $this->assertSame(6, $summary['clients']);
        $this->assertSame(2, $summary['products']);
        $this->assertSame(1, $summary['active_products']);
        $this->assertSame(120000, $summary['debt_cents']);
        $this->assertSame(30000, $summary['credit_cents']);
        $this->assertSame(70000, $summary['received_cents']);
        $this->assertSame(10000, $summary['applicable_cents']);
        $this->assertSame(10000, $summary['overdue_cents']);
        $this->assertSame([$overdue->id, $applicable->id, $debt->id, $credit->id, $history->id, $empty->id], collect($response->viewData('accounts')->items())->pluck('client.id')->all());
        $this->get(route('dashboard', ['account_state' => 'credit', 'q' => 'A Sin movimientos']))->assertOk()->assertViewHas('accounts', fn ($p) => $p->total() === 6)->assertDontSee('name="q"', false)->assertDontSee('name="account_state"', false);
    }

    public function test_cash_is_counted_once_and_voids_recompute_debt_credit_and_initials(): void
    {
        $user = $this->user();
        $client = $this->client($user, 'Cliente contable');
        $a = $this->order($user, $client, '100.00');
        $b = $this->order($user, $client);
        $receipt = $this->receipt($user, $client, '300.00');
        $p = app(ReceiptService::class)->apply($user, $receipt, $a->id, ['token' => (string) Str::uuid(), 'amount' => '40.00']);
        app(ReceiptService::class)->apply($user, $receipt, $b->id, ['token' => (string) Str::uuid(), 'amount' => '50.00']);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $summary = $response->viewData('summary');
        $this->assertSame(30000, $summary['received_cents']);
        $this->assertSame(71000, $summary['debt_cents']);
        $this->assertSame(21000, $summary['credit_cents']);
        $this->assertSame(6000, $summary['initial_cents']);
        $admin = $this->user('admin');
        app(SalesService::class)->void($admin, $p, 'Reasignar aplicación a otra venta');
        $this->get(route('dashboard'))->assertViewHas('summary', fn ($s) => $s['debt_cents'] === 75000 && $s['credit_cents'] === 25000 && $s['received_cents'] === 30000);
        app(ReceiptService::class)->void($admin, $receipt, 'Ingreso duplicado por equivocación');
        $this->get(route('dashboard'))->assertViewHas('summary', fn ($s) => $s['debt_cents'] === 80000 && $s['credit_cents'] === 0 && $s['received_cents'] === 0 && $s['initial_cents'] === 10000);
    }

    public function test_dashboard_scopes_clients_money_and_catalog_for_each_role_and_dealership(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $advisor = $this->user('user', $a->id);
        $colleague = $this->user('user', $a->id);
        $foreign = $this->user('user', $b->id);
        $manager = $this->user('dealership_admin', $a->id);
        $admin = $this->user('admin');
        $mine = $this->client($advisor, 'Cliente propio');
        $other = $this->client($colleague, 'Cliente compañero');
        $hidden = $this->client($foreign, 'Cliente sede ajena');
        $this->order($advisor, $mine);
        $this->receipt($advisor, $mine, '40.00');
        $this->order($foreign, $hidden);
        $this->receipt($foreign, $hidden, '200.00');
        Product::factory()->count(2)->create(['dealership_id' => $a->id]);
        Product::factory()->create(['dealership_id' => $b->id]);
        $this->actingAs($advisor)->get(route('dashboard', ['dealership_id' => $b->id]))->assertOk()->assertViewHas('summary', fn ($s) => $s['clients'] === 1 && $s['products'] === 2 && $s['credit_cents'] === 4000)->assertDontSee($other->name)->assertDontSee($hidden->name);
        $this->actingAs($manager)->get(route('dashboard'))->assertViewHas('summary', fn ($s) => $s['clients'] === 2 && $s['products'] === 2 && $s['debt_cents'] === 40000)->assertDontSee($hidden->name);
        $this->actingAs($admin)->get(route('dashboard', ['dealership_id' => '']))->assertViewHas('summary', fn ($s) => $s['clients'] === 3 && $s['products'] === 3 && $s['credit_cents'] === 24000);
        $this->get(route('dashboard', ['dealership_id' => $b->id]))->assertViewHas('summary', fn ($s) => $s['clients'] === 1 && $s['products'] === 1 && $s['credit_cents'] === 20000)->assertDontSee($mine->name);
    }

    public function test_pagination_keeps_empty_clients_after_financial_accounts(): void
    {
        $user = $this->user();
        for ($n = 0; $n < 12; $n++) {
            $this->client($user, 'A vacío '.str_pad($n, 2, '0', STR_PAD_LEFT));
        }
        $client = $this->client($user, 'Z Con deuda');
        $this->order($user, $client);
        $this->actingAs($user)->get(route('dashboard'))->assertViewHas('accounts', fn ($p) => $p->total() === 13 && count($p->items()) === 10 && $p->first()['client']->id === $client->id);
        $this->get(route('dashboard', ['page' => 2]))->assertViewHas('accounts', fn ($p) => count($p->items()) === 3 && collect($p->items())->every(fn ($a) => $a['priority'] === 5));
    }

    public function test_empty_portfolio_and_empty_search_render_zero_totals(): void
    {
        $this->actingAs($this->user())->get(route('dashboard', ['q' => '']))->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['clients'] === 0 && $s['products'] === 0 && $s['debt_cents'] === 0 && $s['credit_cents'] === 0 && $s['received_cents'] === 0)
            ->assertViewHas('accounts', fn ($p) => $p->total() === 0);
    }

    public function test_legacy_initial_without_a_schedule_row_is_counted_until_it_is_paid(): void
    {
        $user = $this->user();
        $client = $this->client($user, 'Inicial anterior');
        $order = $this->order($user, $client, '100.00');
        $order->installments()->where('number', 0)->delete();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewHas('summary', fn ($s) => $s['initial_cents'] === 10000);
        app(SalesService::class)->pay($user, $order->id, ['token' => (string) Str::uuid(), 'amount' => '50.00', 'payment_method_id' => PaymentMethod::first()->id]);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('summary', fn ($s) => $s['initial_cents'] === 5000 && $s['debt_cents'] === 35000);
    }

    public function test_date_range_filters_received_cash_sales_and_new_records_without_hiding_current_balances(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $user = $this->user();
        $client = $this->client($user, 'Cliente del período');
        Product::factory()->create(['dealership_id' => $user->dealership_id]);
        $order = $this->order($user, $client, '100.00');
        $receipt = $this->receipt($user, $client, '100.00');
        app(ReceiptService::class)->apply($user, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => '40.00']);
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        Product::factory()->create(['dealership_id' => $user->dealership_id]);
        $this->client($user, 'Cliente nuevo');
        $this->order($user, $client);
        $this->receipt($user, $client, '50.00');
        app(ReceiptService::class)->apply($user, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => '50.00']);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $summary = $response->viewData('summary');
        $this->assertSame(5000, $summary['received_cents']);
        $this->assertSame(71000, $summary['debt_cents']);
        $this->assertSame(6000, $summary['credit_cents']);
        $this->assertSame(1, $summary['period_orders']);
        $this->assertSame(40000, $summary['period_sold_cents']);
        $this->assertSame(1, $summary['new_clients']);
        $this->assertSame(1, $summary['new_products']);
        $this->assertEquals([50], $response->viewData('chart')->all());
        $this->assertSame(5000, $response->viewData('accounts')->first()['received_cents']);
        $wide = $this->get(route('dashboard', ['from' => '2026-10-26', 'to' => '2026-10-27']))->assertOk();
        $this->assertSame(15000, $wide->viewData('summary')['received_cents']);
        $this->assertSame(2, $wide->viewData('summary')['period_orders']);
        $this->assertSame(2, $wide->viewData('summary')['new_products']);
        $this->assertEquals([100, 50], $wide->viewData('chart')->all());
        $this->get(route('dashboard', ['from' => '2026-10-25', 'to' => '2026-10-25']))->assertOk()->assertViewHas('summary', fn ($s) => $s['received_cents'] === 0 && $s['period_orders'] === 0 && $s['debt_cents'] === 71000 && $s['credit_cents'] === 6000);
        $this->get(route('dashboard', ['from' => '2026-01-01', 'to' => '2026-10-27']))->assertOk()->assertViewHas('chart', fn ($chart) => $chart->count() === 10);
        $this->get(route('dashboard', ['from' => '2000-01-01', 'to' => '2026-10-27']))->assertOk()->assertViewHas('chart', fn ($chart) => $chart->count() === 27);
    }

    public function test_default_dates_roll_forward_every_day_but_custom_ranges_are_preserved(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        $this->actingAs($this->user())->get(route('dashboard'))->assertOk()->assertViewHas('dateFrom', '2026-10-27')->assertViewHas('dateTo', '2026-10-27')->assertViewHas('dateMode', 'today');
        $this->get(route('dashboard', ['date_mode' => 'today', 'from' => '2026-10-26', 'to' => '2026-10-26']))->assertViewHas('dateFrom', '2026-10-27')->assertViewHas('dateTo', '2026-10-27');
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        $this->get(route('dashboard', ['date_mode' => 'today', 'from' => '2026-10-27', 'to' => '2026-10-27']))->assertViewHas('dateFrom', '2026-10-28')->assertViewHas('dateTo', '2026-10-28');
        $this->get(route('dashboard', ['from' => '2026-10-01', 'to' => '2026-10-05', 'date_mode' => 'custom']))->assertViewHas('dateFrom', '2026-10-01')->assertViewHas('dateTo', '2026-10-05')->assertViewHas('dateMode', 'custom');
        $this->get(route('dashboard'))->assertViewHas('dateFrom', '2026-10-28');
        $this->get(route('dashboard', ['from' => '2026-10-29', 'to' => '2026-10-28']))->assertSessionHasErrors('to');
        $this->get(route('dashboard', ['from' => 'invalid']))->assertSessionHasErrors('from');
    }
}
