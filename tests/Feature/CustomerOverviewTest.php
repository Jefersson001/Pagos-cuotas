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

    public function test_empty_overview_shows_two_zero_panels_and_requires_authentication(): void
    {
        $this->get(route('workspace.customers'))->assertRedirect(route('login'));
        $this->actingAs($this->user('admin'))->get(route('workspace.customers'))->assertOk()->assertSee('Resumen de clientes')->assertSee('Sin saldos pendientes de atención.')->assertViewHas('groups', fn ($groups) => $groups['person']['total'] === 0 && $groups['dealership']['total'] === 0 && $groups['person']['overdue_percent'] === 0 && $groups['dealership']['attention']->isEmpty());
    }
}
