<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    }

    private function user(string $role = 'user', ?int $company = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company]);
    }

    private function client(User $owner, string $name = 'Comprador', string $kind = 'person'): Client
    {
        return Client::create(['user_id' => $owner->id, 'dealership_id' => $owner->dealership_id, 'name' => $name, 'customer_kind' => $kind, 'active' => true, 'document' => (string) Str::uuid(), 'phone' => '04121234567']);
    }

    private function sale(User $owner, Client $client, string $total = '100.00', string $initial = '0', int $count = 4, string $due = '2026-11-01'): Order
    {
        return app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta del resumen', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => $total]], 'initial_amount' => $initial, 'installment_count' => $count, 'frequency' => 'monthly', 'first_due' => $due]);
    }

    private function receive(User $owner, Client $client, string $amount, string $methodName = 'Efectivo'): Receipt
    {
        $method = PaymentMethod::firstOrCreate(['dealership_id' => $client->dealership_id, 'name' => $methodName], ['active' => true]);

        $receipt = app(ReceiptService::class)->receive($owner, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id]);
        $this->approvePendingReceipts();

        return $receipt->fresh();
    }

    private function apply(User $owner, Receipt $receipt, Order $order, string $amount): Payment
    {
        return app(ReceiptService::class)->apply($owner, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => $amount]);
    }

    public function test_combined_summary_separates_sales_cash_applications_and_available_credit(): void
    {
        $owner = $this->user('dealership_admin');
        $borrower = $this->client($owner, '<script>alert("cliente")</script>');
        $dealer = $this->client($owner, 'Concesionario', 'dealership');
        $creditOnly = $this->client($owner, 'Sin deuda');
        $cancelledClient = $this->client($owner, 'Venta cancelada');
        $oldSale = $this->sale($owner, $borrower, '1000.00', '200.00', 4, '2026-10-01');
        $oldSale->update(['created_at' => '2026-09-30 23:59:59']);
        $oldCash = $this->receive($owner, $borrower, '400.00');
        $oldCash->update(['created_at' => '2026-09-30 23:59:59']);
        $this->apply($owner, $oldCash, $oldSale, '100.00');
        $currentCash = $this->receive($owner, $borrower, '300.00');
        $this->apply($owner, $currentCash, $oldSale, '250.00');
        $voidedApplication = $this->apply($owner, $oldCash, $oldSale, '50.00');
        app(SalesService::class)->void($this->user('admin'), $voidedApplication, 'Reversar aplicación');
        $this->receive($owner, $borrower, '50.00');
        $paidSale = $this->sale($owner, $dealer, '200.00');
        $methodName = '<script>alert("metodo")</script>';
        $this->apply($owner, $this->receive($owner, $dealer, '200.00', $methodName), $paidSale, '200.00');
        $this->receive($owner, $creditOnly, '1000.00');
        $this->sale($owner, $cancelledClient, '500.00')->update(['cancelled' => true]);
        $this->receive($owner, $cancelledClient, '20.00');
        $voidedCash = $this->receive($owner, $borrower, '900.00');
        $this->apply($owner, $voidedCash, $oldSale, '100.00');
        app(ReceiptService::class)->void($this->user('admin'), $voidedCash, 'Pago incorrecto');
        $this->receive($owner, $borrower, '700.00')->update(['created_at' => '2026-10-29 12:00:01']);
        $this->sale($owner, $borrower, '800.00')->update(['created_at' => '2026-10-30 00:00:00']);

        $response = $this->actingAs($owner)->get(route('workspace.sales'))->assertOk();
        $data = $response->original->getData();

        $this->assertSame(1, $data['salesMonth']);
        $this->assertSame(20000, $data['soldMonthCents']);
        $this->assertSame(5, $data['receiptsMonth']);
        $this->assertSame(157000, $data['receivedMonthCents']);
        $this->assertSame(55000, $data['appliedMonthCents']);
        $this->assertSame(10000, $data['oldCreditAppliedCents']);
        $this->assertSame(65000, $data['debtCents']);
        $this->assertSame(1, $data['debtClients']);
        $this->assertSame(1, $data['openOrders']);
        $this->assertSame(5000, $data['overdueCents']);
        $this->assertSame(1, $data['overdueCount']);
        $this->assertSame(8, $data['overduePercent']);
        $this->assertSame(20000, $data['dueSoonCents']);
        $this->assertSame(1, $data['dueSoonCount']);
        $this->assertSame(0, $data['initialCents']);
        $this->assertSame(142000, $data['creditCents']);
        $this->assertSame(3, $data['creditClients']);
        $this->assertSame(40000, $data['applicableCents']);
        $this->assertCount(1, $data['creditAccounts']);
        $this->assertSame($borrower->id, $data['creditAccounts']->first()['client']->id);
        $this->assertSame(2, $data['receiptStates']->get('Parcialmente aplicado'));
        $this->assertSame(3, $data['receiptStates']->get('Sin aplicar'));
        $this->assertSame(1, $data['receiptStates']->get('Aplicado'));
        $this->assertSame(['Atrasado' => 1, 'Pagado' => 1, 'Cancelado' => 1], $data['orderStates']->all());
        $this->assertSame([0, 0, 0, 0, 20000], $data['weeks']->pluck('sold_cents')->all());
        $this->assertSame([0, 0, 0, 0, 157000], $data['weeks']->pluck('received_cents')->all());
        $this->assertSame([137000, 20000], $data['methods']->pluck('amount_cents')->all());
        $this->assertCount(5, $data['recentReceipts']);
        $response->assertSee('Resumen de ventas')->assertSee('Aplicar saldo')->assertSee($borrower->name)->assertDontSee($borrower->name, false)->assertSee($methodName)->assertDontSee($methodName, false)->assertDontSee('module-section-grid', false);

        $this->apply($owner, $oldCash, $oldSale, '100.00');
        $this->get(route('workspace.sales'))->assertOk()->assertViewHas('receivedMonthCents', 157000)->assertViewHas('appliedMonthCents', 65000)->assertViewHas('oldCreditAppliedCents', 20000)->assertViewHas('debtCents', 55000)->assertViewHas('creditCents', 132000)->assertViewHas('overdueCents', 0)->assertViewHas('dueSoonCents', 15000);
    }

    public function test_attention_includes_initials_and_unpaid_balances_with_exact_seven_day_boundaries(): void
    {
        $owner = $this->user();
        $client = $this->client($owner);
        $this->sale($owner, $client, '100.00', '20.00', 4, '2026-10-29');
        $this->sale($owner, $client, '60.00', '0', 3, '2026-11-04');
        $this->sale($owner, $client, '90.00', '0', 3, '2026-11-05');
        $late = $this->sale($owner, $client, '100.00', '0', 4, '2026-10-28');

        $response = $this->actingAs($owner)->get(route('workspace.sales'))->assertOk();

        $response->assertViewHas('debtCents', 35000)->assertViewHas('initialCents', 2000)->assertViewHas('initialCount', 1)->assertViewHas('dueSoonCents', 6000)->assertViewHas('dueSoonCount', 3)->assertViewHas('overdueCents', 2500)->assertViewHas('overdueCount', 1);
        $response->assertViewHas('dueItems', fn ($rows) => $rows->count() === 4 && $rows->first()['order']->id === $late->id)->assertSee('Inicial')->assertSee('Vence hoy')->assertSee('04/11/2026');
    }

    public function test_financial_summary_respects_company_and_advisor_ownership(): void
    {
        $owner = $this->user();
        $team = $this->user('user', $owner->dealership_id);
        $otherCompany = Dealership::factory()->create();
        $foreignOwner = $this->user('user', $otherCompany->id);
        foreach ([[$owner, 'Cuenta propia'], [$team, 'Cuenta compañero'], [$foreignOwner, 'CUENTA RESERVADA']] as [$actor, $name]) {
            $client = $this->client($actor, $name);
            $this->sale($actor, $client);
            $this->receive($actor, $client, '20.00');
        }

        $this->actingAs($owner)->get(route('workspace.sales', ['dealership_id' => $otherCompany->id]))->assertOk()->assertDontSee('CUENTA RESERVADA')->assertDontSee('Cuenta compañero')->assertViewHas('soldMonthCents', 10000)->assertViewHas('receivedMonthCents', 2000)->assertViewHas('debtCents', 10000)->assertViewHas('applicableCents', 2000);
        $manager = $this->user('dealership_admin', $owner->dealership_id);
        $contact = Client::factory()->concessionaire()->for($manager, 'owner')->create(['dealership_id' => $owner->dealership_id]);
        Client::where('dealership_id', $owner->dealership_id)->where('customer_kind', 'person')->update(['concessionaire_id' => $contact->id]);
        $manager->update(['concessionaire_id' => $contact->id]);
        $this->actingAs($manager)->get(route('workspace.sales'))->assertOk()->assertDontSee('CUENTA RESERVADA')->assertViewHas('soldMonthCents', 20000)->assertViewHas('receivedMonthCents', 4000);
        $this->actingAs($this->user('admin'))->get(route('workspace.sales'))->assertOk()->assertSee('CUENTA RESERVADA')->assertViewHas('soldMonthCents', 30000)->assertViewHas('creditCents', 6000);
        $this->get(route('workspace.sales', ['dealership_id' => $owner->dealership_id]))->assertOk()->assertDontSee('CUENTA RESERVADA')->assertViewHas('soldMonthCents', 20000)->assertViewHas('creditCents', 4000);
    }

    public function test_empty_summary_requires_login_and_month_rollover_keeps_historical_balances(): void
    {
        $this->get(route('workspace.sales'))->assertRedirect(route('login'));
        $owner = $this->user();
        $this->actingAs($owner)->get(route('workspace.sales'))->assertOk()->assertViewHas('receivedMonthCents', 0)->assertViewHas('debtCents', 0)->assertViewHas('creditCents', 0)->assertViewHas('overduePercent', 0)->assertSee('Todavía no hay pagos confirmados este mes.')->assertSee('No hay cuotas vencidas ni vencimientos en los próximos 7 días.');

        $client = $this->client($owner);
        $this->sale($owner, $client)->update(['created_at' => '2026-12-31 23:59:59']);
        $this->receive($owner, $client, '20.00')->update(['created_at' => '2026-12-31 23:59:59']);
        $this->travelTo(now()->setDate(2027, 1, 1)->startOfDay());

        $this->get(route('workspace.sales'))->assertOk()->assertViewHas('salesMonth', 0)->assertViewHas('receivedMonthCents', 0)->assertViewHas('debtCents', 10000)->assertViewHas('creditCents', 2000)->assertViewHas('weeks', fn ($weeks) => $weeks->count() === 1 && $weeks->first()['sold_cents'] === 0)->assertSee('01/01/2027');
    }

    public function test_aging_uses_remaining_amounts_and_exact_day_boundaries_and_prioritizes_debtors(): void
    {
        $owner = $this->user();
        $late = $this->client($owner, 'Cliente atrasado');
        $current = $this->client($owner, 'Concesionario al día', 'dealership');
        foreach ([1, 30, 31, 60, 61, 90, 91, 0, -1] as $days) {
            $order = $this->sale($owner, $late, '100.00', '0', 1, today()->subDays($days)->toDateString());
            if ($days === 30) {
                $this->apply($owner, $this->receive($owner, $late, '25.00'), $order, '25.00');
            }
        }
        $this->sale($owner, $current, '5000.00', '0', 1, '2027-01-01');
        $this->sale($owner, $late, '999.00', '0', 1, today()->subDays(120)->toDateString())->update(['cancelled' => true]);
        $this->receive($owner, $this->client($owner, 'Solo crédito'), '1000.00');

        $response = $this->actingAs($owner)->get(route('workspace.sales'))->assertOk();
        $aging = $response->viewData('aging');
        $debtors = $response->viewData('topDebtors');

        $this->assertSame([17500, 20000, 20000, 10000], $aging->pluck('amount_cents')->all());
        $this->assertSame([2, 2, 2, 1], $aging->pluck('count')->all());
        $response->assertViewHas('overdueCents', 67500);
        $this->assertCount(2, $debtors);
        $this->assertSame($late->id, $debtors->first()['client']->id);
        $this->assertSame(87500, $debtors->first()['debt_cents']);
        $this->assertSame(67500, $debtors->first()['overdue_cents']);
        $this->assertSame(91, $debtors->first()['days_overdue']);
        $this->assertSame($current->id, $debtors->last()['client']->id);
        $this->assertSame(0, $debtors->last()['days_overdue']);
    }

    public function test_comparison_uses_the_same_elapsed_period_and_ignores_cancelled_sales_and_voided_cash(): void
    {
        $owner = $this->user('dealership_admin');
        $client = $this->client($owner);
        $this->sale($owner, $client, '100.00')->update(['created_at' => '2026-09-01 00:00:00']);
        $this->receive($owner, $client, '80.00')->update(['created_at' => '2026-09-29 12:00:00']);
        $this->sale($owner, $client, '1000.00')->update(['created_at' => '2026-09-29 12:00:01']);
        $this->receive($owner, $client, '2000.00')->update(['created_at' => '2026-09-30 00:00:00']);
        $this->sale($owner, $client, '150.00')->update(['created_at' => '2026-10-01 00:00:00']);
        $this->receive($owner, $client, '120.00')->update(['created_at' => '2026-10-01 00:00:00']);
        $this->sale($owner, $client, '999.00')->update(['cancelled' => true]);
        app(ReceiptService::class)->void($this->user('admin'), $this->receive($owner, $client, '900.00'), 'Ingreso incorrecto');

        $response = $this->actingAs($owner)->get(route('workspace.sales'))->assertOk();
        $comparison = $response->viewData('comparison');

        $this->assertSame(15000, $comparison['sales']['current_cents']);
        $this->assertSame(10000, $comparison['sales']['previous_cents']);
        $this->assertSame(5000, $comparison['sales']['difference_cents']);
        $this->assertSame(50.0, $comparison['sales']['change_percent']);
        $this->assertSame(12000, $comparison['receipts']['current_cents']);
        $this->assertSame(8000, $comparison['receipts']['previous_cents']);
        $this->assertSame(50.0, $comparison['receipts']['change_percent']);
        $this->assertSame('2026-09-29 12:00:00', $response->viewData('previousEnd')->toDateTimeString());

        $this->travelTo(now()->setDate(2026, 11, 1));
        $this->get(route('workspace.sales'))->assertOk()->assertViewHas('comparison', fn ($rows) => $rows['sales']['current_cents'] === 0 && $rows['sales']['previous_cents'] === 15000 && $rows['sales']['change_percent'] === -100.0);
    }

    public function test_comparison_handles_empty_periods_and_shorter_previous_months(): void
    {
        $owner = $this->user();
        $this->actingAs($owner)->get(route('workspace.sales'))->assertOk()->assertViewHas('comparison', fn ($rows) => $rows['sales']['change_percent'] === 0.0 && $rows['receipts']['change_percent'] === 0.0);
        $this->sale($owner, $this->client($owner));

        $this->get(route('workspace.sales'))->assertOk()->assertViewHas('comparison', fn ($rows) => $rows['sales']['change_percent'] === null)->assertSee('Sin base anterior');
        $this->travelTo(now()->setDate(2026, 3, 31));
        $this->get(route('workspace.sales'))->assertOk()->assertViewHas('previousEnd', fn ($date) => $date->toDateString() === '2026-02-28');
        $this->travelTo(now()->setDate(2028, 3, 31));
        $this->get(route('workspace.sales'))->assertOk()->assertViewHas('previousEnd', fn ($date) => $date->toDateString() === '2028-02-29');
    }

    public function test_legacy_initial_without_schedule_keeps_its_remaining_balance(): void
    {
        $owner = $this->user('dealership_admin');
        $order = $this->sale($owner, $this->client($owner), '400.00', '100.00');
        $order->installments()->where('number', 0)->delete();
        $this->actingAs($owner)->get(route('workspace.sales'))->assertOk()->assertViewHas('initialCents', 10000)->assertViewHas('initialCount', 1);

        app(SalesService::class)->pay($owner, $order->id, ['token' => (string) Str::uuid(), 'amount' => '50.00', 'payment_method_id' => PaymentMethod::first()->id]);
        $this->approvePendingReceipts();

        $this->get(route('workspace.sales'))->assertOk()->assertViewHas('initialCents', 5000)->assertViewHas('initialCount', 1)->assertViewHas('debtCents', 35000);
    }
}
