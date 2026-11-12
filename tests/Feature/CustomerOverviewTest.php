<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    }

    private function user(string $role = 'user', ?int $company = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company]);
    }

    private function customer(User $owner, string $name, string $kind = 'person'): Client
    {
        return Client::create(['user_id' => $owner->id, 'dealership_id' => $owner->dealership_id, 'name' => $name, 'customer_kind' => $kind, 'document' => (string) Str::uuid(), 'phone' => '04121234567', 'active' => true]);
    }

    private function sale(User $owner, Client $client, string $total, string $initial, int $count, string $due): Order
    {
        return app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta del resumen', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => $total]], 'initial_amount' => $initial, 'installment_count' => $count, 'frequency' => 'monthly', 'first_due' => $due]);
    }

    private function receive(User $owner, Client $client, string $amount): Receipt
    {
        $method = PaymentMethod::firstOrCreate(['dealership_id' => $client->dealership_id, 'name' => 'Efectivo'], ['active' => true]);

        $receipt = app(ReceiptService::class)->receive($owner, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id]);
        $this->approvePendingReceipts();

        return $receipt->fresh();
    }

    public function test_overview_separates_financial_balances_by_type_and_counts_cash_only_once(): void
    {
        $owner = $this->user();
        $late = $this->customer($owner, 'Cliente vencido');
        $creditOnly = $this->customer($owner, 'Cliente con anticipo');
        $inactive = $this->customer($owner, 'Cliente inactivo con deuda');
        $dealer = $this->customer($owner, 'Concesionario activo', 'dealership');
        $emptyDealer = $this->customer($owner, 'Concesionario sin movimientos', 'dealership');
        $emptyDealer->update(['active' => false]);
        $sale = $this->sale($owner, $late, '100.00', '20.00', 4, '2026-10-01');
        $sale->update(['created_at' => '2026-10-05 12:00:00']);
        $previousCash = $this->receive($owner, $late, '100.00');
        $previousCash->update(['created_at' => '2026-09-30 23:59:59']);
        $cash = $this->receive($owner, $late, '70.00');
        app(ReceiptService::class)->apply($owner, $cash, $sale->id, ['token' => (string) Str::uuid(), 'amount' => '30.00']);
        $this->receive($owner, $creditOnly, '1000.00');
        $this->sale($owner, $inactive, '1000.00', '0', 5, '2026-10-30');
        $inactive->update(['active' => false]);
        $this->sale($owner, $dealer, '200.00', '0', 5, '2026-11-01');
        $this->receive($owner, $dealer, '30.00');
        $this->receive($owner, $dealer, '900.00')->update(['voided_at' => now()]);
        $this->sale($owner, $dealer, '1000.00', '0', 5, '2026-10-01')->update(['cancelled' => true]);

        $response = $this->actingAs($owner)->get(route('workspace.customers'))->assertOk();
        $groups = $response->viewData('groups');
        $people = $groups['person'];
        $this->assertSame(3, $people['total']);
        $this->assertSame(2, $people['active']);
        $this->assertSame(3, $people['new_this_month']);
        $this->assertSame(107000, $people['debt_cents']);
        $this->assertSame(114000, $people['credit_cents']);
        $this->assertSame(107000, $people['received_month_cents']);
        $this->assertSame(110000, $people['sold_month_cents']);
        $this->assertSame(1000, $people['overdue_cents']);
        $this->assertSame(22000, $people['due_soon_cents']);
        $this->assertSame(7000, $people['applicable_cents']);
        $this->assertSame(2, $people['open_orders']);
        $this->assertSame(3, $people['unapplied_receipts']);
        $this->assertSame($late->id, $people['attention']->first()['client']->id);
        $this->assertSame(2, $groups['dealership']['total']);
        $this->assertSame(1, $groups['dealership']['active']);
        $this->assertSame(20000, $groups['dealership']['debt_cents']);
        $this->assertSame(3000, $groups['dealership']['credit_cents']);
        $this->assertSame(3000, $groups['dealership']['received_month_cents']);
        $this->assertSame(20000, $groups['dealership']['sold_month_cents']);
        $this->assertSame(4000, $groups['dealership']['due_soon_cents']);
        $this->assertSame(0, $groups['dealership']['overdue_cents']);
        $this->assertSame(1, $groups['dealership']['sales_month']);
        $response->assertSee('Requieren atención')->assertSee('Aplicar saldo')->assertDontSee('module-section-grid', false);

        app(ReceiptService::class)->apply($owner, $previousCash, $sale->id, ['token' => (string) Str::uuid(), 'amount' => '10.00']);
        $this->get(route('workspace.customers'))->assertViewHas('groups', fn ($data) => $data['person']['received_month_cents'] === 107000 && $data['person']['overdue_cents'] === 0 && $data['person']['debt_cents'] === 106000);
    }

    public function test_advisors_managers_and_admins_only_see_authorized_portfolios(): void
    {
        $owner = $this->user();
        $team = $this->user('user', $owner->dealership_id);
        $otherCompany = Dealership::factory()->create();
        $foreignOwner = $this->user('user', $otherCompany->id);
        $mine = $this->customer($owner, 'Cuenta propia');
        $colleague = $this->customer($team, 'Cuenta del compañero');
        $foreign = $this->customer($foreignOwner, 'NEGOCIO RESERVADO', 'dealership');
        foreach ([[$owner, $mine], [$team, $colleague], [$foreignOwner, $foreign]] as [$actor, $client]) {
            $this->sale($actor, $client, '100.00', '0', 5, '2026-11-01');
        }
        $this->actingAs($owner)->get(route('workspace.customers', ['dealership_id' => $otherCompany->id]))->assertOk()->assertDontSee('NEGOCIO RESERVADO')->assertDontSee('Cuenta del compañero')->assertViewHas('groups', fn ($groups) => $groups['person']['total'] === 1 && $groups['person']['debt_cents'] === 10000 && $groups['dealership']['total'] === 0);
        $manager = $this->user('dealership_admin', $owner->dealership_id);
        $contact = Client::factory()->concessionaire()->for($manager, 'owner')->create(['dealership_id' => $owner->dealership_id]);
        Client::whereIn('id', [$mine->id, $colleague->id])->update(['concessionaire_id' => $contact->id]);
        $manager->update(['concessionaire_id' => $contact->id]);
        $this->actingAs($manager)->get(route('workspace.customers'))->assertViewHas('groups', fn ($groups) => count($groups) === 1 && $groups['person']['total'] === 2);
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('workspace.customers'))->assertViewHas('groups', fn ($groups) => $groups['person']['total'] === 2 && $groups['dealership']['total'] === 2);
        $this->get(route('workspace.customers', ['dealership_id' => $owner->dealership_id]))->assertDontSee('NEGOCIO RESERVADO')->assertViewHas('groups', fn ($groups) => $groups['dealership']['total'] === 1);
    }

    public function test_admin_and_head_office_share_filtered_customer_and_dealer_portfolios(): void
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $otherCompany = Dealership::factory()->create(['organization_type' => 'head_office']);
        $admin = $this->user('admin');
        $head = $this->user('head_office', $company->id);
        $north = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Concesionario Norte']);
        $south = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Concesionario Sur']);
        $empty = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Sin cartera']);
        $foreign = Client::factory()->concessionaire()->create(['dealership_id' => $otherCompany->id, 'name' => 'Concesionario ajeno']);
        $dealer = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'dealership_admin')->value('id'), 'concessionaire_id' => $north->id]);
        $a = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id, 'name' => 'Cliente Norte']);
        $b = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $south->id, 'name' => 'Cliente Sur']);
        $c = Client::factory()->create(['dealership_id' => $otherCompany->id, 'concessionaire_id' => $foreign->id, 'name' => 'Cliente ajeno']);
        $sale = $this->sale($admin, $a, '100', '0', 2, '2026-10-01');
        $this->sale($admin, $b, '200', '0', 2, '2026-11-01');
        $this->sale($admin, $c, '300', '0', 2, '2026-11-01');
        $receipt = $this->receive($admin, $a, '60');
        app(ReceiptService::class)->apply($admin, $receipt, $sale->id, ['token' => (string) Str::uuid(), 'amount' => '20']);
        $excluded = [];
        foreach (['pending', 'rejected'] as $status) {
            $excluded[$status] = $this->receive($admin, $a, '500');
        }
        $this->receive($admin, $a, '700')->update(['voided_at' => now()]);
        foreach ($excluded as $status => $payment) {
            $payment->update(['approval_status' => $status]);
        }
        $this->sale($admin, $a, '900', '0', 2, '2026-10-01')->update(['cancelled' => true]);

        $response = $this->actingAs($admin)->get(route('workspace.customers'))->assertOk()->assertViewIs('workspace.customers');
        foreach (['person', 'dealership'] as $kind) {
            $this->assertSame(58000, $response->viewData('groups')[$kind]['debt_cents']);
            $this->assertSame(4000, $response->viewData('groups')[$kind]['credit_cents']);
            $this->assertSame(6000, $response->viewData('groups')[$kind]['received_month_cents']);
        }
        foreach ([[$admin, 'workspace.customers'], [$head, 'head-office.overview']] as [$actor, $route]) {
            $response = $this->actingAs($actor)->get(route($route, ['concessionaire_id' => $north->id]))->assertOk()->assertViewIs('workspace.customers')->assertDontSee('Cliente Sur');
            foreach (['person', 'dealership'] as $kind) {
                $group = $response->viewData('groups')[$kind];
                $this->assertSame(1, $group['total']);
                $this->assertSame(8000, $group['debt_cents']);
                $this->assertSame(4000, $group['credit_cents']);
                $this->assertSame(3000, $group['overdue_cents']);
                $this->assertSame(1, $group['sales_month']);
                $this->assertSame(10000, $group['sold_month_cents']);
            }
            $this->get(route($route, ['concessionaire_id' => $empty->id]))->assertViewHas('groups', fn (array $groups): bool => $groups['person']['total'] === 0 && $groups['dealership']['total'] === 1 && $groups['dealership']['debt_cents'] === 0);
        }
        $response = $this->actingAs($head)->get(route('head-office.overview'))->assertOk()->assertDontSee('Cliente ajeno')->assertDontSee('Concesionario ajeno')->assertDontSee('Aplicar saldo');
        $this->assertSame(2, $response->viewData('groups')['person']['total']);
        $this->assertSame(3, $response->viewData('groups')['dealership']['total']);
        $this->assertSame(28000, $response->viewData('groups')['dealership']['debt_cents']);
        $this->assertNull($response->viewData('groups')['person']['create_route']);
        foreach ([$foreign->id, $a->id, 999999] as $id) {
            $this->getJson(route('head-office.overview', ['concessionaire_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('concessionaire_id');
        }
        $this->get(route('head-office.clients', ['concessionaire_id' => $north->id, 'account_state' => 'debt']))->assertOk()->assertViewHas('clients', fn ($clients): bool => $clients->total() === 1 && $clients->first()->id === $a->id);
        $this->get(route('clients.index', ['concessionaire_id' => $north->id, 'account_state' => 'debt']))->assertOk()->assertViewHas('clients', fn ($clients): bool => $clients->total() === 1 && $clients->first()->id === $north->id);
        $this->actingAs($dealer)->get(route('workspace.customers', ['concessionaire_id' => $south->id]))->assertOk()->assertDontSee('Cliente Sur')->assertViewHas('groups', fn (array $groups): bool => array_keys($groups) === ['person'] && $groups['person']['total'] === 1 && $groups['person']['debt_cents'] === 8000)->assertViewHas('canFilterConcessionaires', false);
    }

    public function test_empty_overview_shows_two_zero_panels_and_requires_authentication(): void
    {
        $this->get(route('workspace.customers'))->assertRedirect(route('login'));
        $this->actingAs($this->user('admin'))->get(route('workspace.customers'))->assertOk()->assertSee('Resumen de contactos')->assertSee('Sin saldos pendientes de atención.')->assertViewHas('groups', fn ($groups) => $groups['person']['total'] === 0 && $groups['dealership']['total'] === 0 && $groups['person']['overdue_percent'] === 0 && $groups['dealership']['attention']->isEmpty());
    }
}
