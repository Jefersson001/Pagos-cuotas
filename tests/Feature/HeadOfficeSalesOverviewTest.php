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
use Illuminate\Support\Str;
use Tests\TestCase;

class HeadOfficeSalesOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(12, 0));
    }

    private function sale(User $actor, Client $client, string $amount, string $description, bool $overdue = false): Order
    {
        return app(SalesService::class)->create($actor, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => $description, 'items' => [['description' => $description, 'quantity' => 1, 'unit_price' => $amount]], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => ($overdue ? today()->subDay() : today()->addMonth())->toDateString()]);
    }

    private function receive(User $actor, Client $client, PaymentMethod $method, string $amount): Receipt
    {
        return app(ReceiptService::class)->receive($actor, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id]);
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreignCompany = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $north = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Concesionario Norte']);
        $south = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Concesionario Sur']);
        $empty = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $foreignDealer = Client::factory()->concessionaire()->create(['dealership_id' => $foreignCompany->id, 'name' => 'Concesionario externo secreto']);
        $client = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id, 'name' => 'Cliente Norte']);
        $other = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $south->id, 'name' => 'Cliente Sur']);
        $foreignClient = Client::factory()->create(['dealership_id' => $foreignCompany->id, 'concessionaire_id' => $foreignDealer->id, 'name' => 'Cliente externo secreto']);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $north->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia', 'active' => true]);
        $foreignMethod = PaymentMethod::create(['dealership_id' => $foreignCompany->id, 'name' => 'Cuenta externa secreta', 'active' => true]);
        $order = $this->sale($admin, $client, '100.00', 'Venta Norte', true);
        $second = $this->sale($admin, $other, '300.00', 'Venta Sur');
        $this->sale($admin, $client, '900.00', 'Venta cancelada')->update(['cancelled' => true]);
        $this->sale($admin, $foreignClient, '500.00', 'Venta externa secreta');
        $receipt = $this->receive($admin, $client, $method, '80.00');
        $otherReceipt = $this->receive($admin, $other, $method, '20.00');
        $service = app(ReceiptService::class);
        $service->apply($admin, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => '30.00']);
        $service->apply($admin, $otherReceipt, $second->id, ['token' => (string) Str::uuid(), 'amount' => '20.00']);
        $this->receive($admin, $client, $method, '30.00')->update(['created_at' => now()->subMonthNoOverflow()]);
        $this->receive($dealer, $client, $method, '15.00');
        $service->reject($head, $this->receive($dealer, $client, $method, '25.00'), 'Comprobante incorrecto');
        $service->void($admin, $this->receive($admin, $client, $method, '40.00'), 'Ingreso duplicado de prueba');
        $this->receive($admin, $foreignClient, $foreignMethod, '400.00');

        return compact('head', 'north', 'south', 'empty', 'foreignDealer', 'client', 'dealer', 'order');
    }

    public function test_general_overview_aggregates_only_the_heads_customers_and_confirmed_payments(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('head-office.sales.overview', ['dealership_id' => $data['foreignDealer']->dealership_id]))->assertOk()
            ->assertViewHas('clientCount', 2)->assertViewHas('salesMonth', 2)->assertViewHas('soldMonthCents', 40000)
            ->assertViewHas('receivedMonthCents', 10000)->assertViewHas('appliedMonthCents', 5000)
            ->assertViewHas('debtCents', 35000)->assertViewHas('creditCents', 8000)->assertViewHas('overdueCents', 2000)
            ->assertSee('Solo consulta')->assertSee('Cliente Norte')->assertSee('Cliente Sur')
            ->assertDontSee('Cliente externo secreto')->assertDontSee('Cuenta externa secreta')->assertDontSee('Concesionario externo secreto')
            ->assertDontSee('Nueva venta')->assertDontSee('Registrar pago')->assertDontSee('>Aplicar saldo<', false);
    }

    public function test_dealer_filter_updates_every_metric_and_detail_link_including_empty_portfolios(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('head-office.sales.overview', ['concessionaire_id' => $data['north']->id]))->assertOk()
            ->assertViewHas('clientCount', 1)->assertViewHas('soldMonthCents', 10000)->assertViewHas('receivedMonthCents', 8000)
            ->assertViewHas('debtCents', 7000)->assertViewHas('creditCents', 8000)->assertViewHas('appliedMonthCents', 3000)
            ->assertViewHas('topDebtors', fn ($rows) => $rows->pluck('client.id')->all() === [$data['client']->id])
            ->assertSee(route('head-office.orders.index', ['concessionaire_id' => $data['north']->id]), false)
            ->assertSee(route('head-office.orders.show', $data['order']), false)->assertDontSee('Cliente Sur');
        $this->get(route('head-office.sales.overview', ['concessionaire_id' => $data['south']->id]))->assertOk()
            ->assertViewHas('soldMonthCents', 30000)->assertViewHas('receivedMonthCents', 2000)->assertViewHas('debtCents', 28000)->assertViewHas('creditCents', 0)->assertViewHas('overdueCents', 0);
        $this->get(route('head-office.sales.overview', ['concessionaire_id' => $data['empty']->id]))->assertOk()
            ->assertViewHas('clientCount', 0)->assertViewHas('soldMonthCents', 0)->assertViewHas('debtCents', 0)->assertViewHas('creditCents', 0);
        $this->get(route('head-office.sales.overview', ['concessionaire_id' => $data['foreignDealer']->id]))->assertSessionHasErrors('concessionaire_id');
        $this->get(route('head-office.sales.overview', ['concessionaire_id' => $data['client']->id]))->assertSessionHasErrors('concessionaire_id');
        $this->actingAs($data['dealer'])->get(route('head-office.sales.overview'))->assertForbidden();

    }
}
