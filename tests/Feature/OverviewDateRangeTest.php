<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OverviewDateRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(12, 0));
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $branch = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $client = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $finance = Product::factory()->create(['dealership_id' => $company->id, 'price' => '100', 'initial_amount' => '20', 'pay_in_installments' => true]);
        $cash = Product::factory()->create(['dealership_id' => $company->id, 'price' => '300', 'initial_amount' => '300', 'pay_in_installments' => false]);
        $order = app(SalesService::class)->create($admin, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'items' => [['product_id' => $finance->id, 'quantity' => 1], ['product_id' => $cash->id, 'quantity' => 1]], 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => '2026-11-20']);
        $order->update(['created_at' => '2026-10-10 10:00:00']);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia', 'active' => true]);
        $service = app(ReceiptService::class);
        $receipt = $service->receive($admin, $client->id, ['token' => (string) Str::uuid(), 'amount' => '120', 'payment_method_id' => $method->id, 'payment_date' => '2026-10-10']);
        $service->apply($admin, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => '20']);
        $pending = $service->receive($dealer, $client->id, ['token' => (string) Str::uuid(), 'amount' => '33', 'payment_method_id' => $method->id, 'payment_date' => '2026-10-11']);

        return compact('admin', 'head', 'dealer', 'branch', 'client', 'finance', 'cash', 'receipt', 'pending', 'order');
    }

    public function test_ranges_use_payment_dates_but_keep_balances_current_for_all_roles(): void
    {
        $data = $this->portfolio();
        $range = ['from' => '2026-10-01', 'to' => '2026-10-31', 'date_mode' => 'custom'];
        foreach (['admin', 'head', 'dealer'] as $role) {
            $this->actingAs($data[$role]);
            $contacts = $this->get(route($role === 'head' ? 'head-office.overview' : 'workspace.customers', $range))->assertOk();
            $group = $contacts->viewData('groups')['person'];
            $this->assertSame(12000, $group['received_month_cents']);
            $this->assertSame(40000, $group['sold_month_cents']);
            $this->assertSame(38000, $group['debt_cents']);
            $this->assertSame(10000, $group['credit_cents']);
            if ($role !== 'dealer') {
                $this->assertSame(38000, $contacts->viewData('groups')['dealership']['debt_cents']);
            }
            $sales = $this->get(route($role === 'head' ? 'head-office.sales.overview' : 'workspace.sales', $range))->assertOk()
                ->assertViewHas('receivedMonthCents', 12000)->assertViewHas('soldMonthCents', 40000)
                ->assertViewHas('debtCents', 38000)->assertViewHas('creditCents', 10000)
                ->assertViewHas('pendingApprovalCents', 3300)->assertViewHas('approvedPeriodCount', 0)
                ->assertViewHas('appliedMonthCents', 0)->assertViewHas('previousStart', fn ($date) => $date->toDateString() === '2026-08-31');
            $this->assertSame(12000, $sales->viewData('weeks')->sum('received_cents'));
            $this->get(route('workspace.products', $range))->assertOk()->assertViewHas('unitsMonth', 2)->assertViewHas('soldMonthCents', 40000)
                ->assertViewHas('averageInitialCents', 2000)->assertViewHas('cashCount', 1)->assertViewHas('installmentCount', 1)
                ->assertSee(route('products.show', $data['finance']));
            $this->get(route($role === 'head' ? 'head-office.sales.overview' : 'workspace.sales'))->assertOk()
                ->assertViewHas('receivedMonthCents', 0)->assertViewHas('soldMonthCents', 0)->assertViewHas('debtCents', 38000)->assertViewHas('approvedPeriodCount', 1);
        }
        $this->actingAs($data['head'])->get(route('receipts.index', $range + ['state' => 'approved', 'date_field' => 'payment']))->assertOk()
            ->assertViewHas('receipts', fn ($receipts) => $receipts->modelKeys() === [$data['receipt']->id]);
        $this->get(route('head-office.clients', $range + ['account_state' => 'received']))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->modelKeys() === [$data['client']->id]);
    }

    public function test_invalid_ranges_and_foreign_dealer_filters_are_rejected(): void
    {
        $data = $this->portfolio();
        $foreign = Client::factory()->concessionaire()->create(['dealership_id' => Dealership::factory()->create()->id]);
        $this->actingAs($data['head']);
        foreach (['head-office.overview', 'head-office.sales.overview', 'workspace.products'] as $route) {
            foreach ([['from' => '2026-11-10', 'to' => '2026-11-01'], ['from' => 'invalid'], ['to' => '2026-11-13']] as $range) {
                $this->getJson(route($route, $range + ['date_mode' => 'custom']))->assertUnprocessable();
            }
            $this->getJson(route($route, ['concessionaire_id' => $foreign->id]))->assertUnprocessable();
        }
    }

    public function test_dealer_summary_excludes_direct_transactions_and_unassigned_clients_have_a_working_link(): void
    {
        $data = $this->portfolio();
        $unassigned = Client::factory()->create(['dealership_id' => $data['branch']->dealership_id, 'concessionaire_id' => null]);
        app(SalesService::class)->create($data['admin'], ['token' => (string) Str::uuid(), 'client_id' => $data['branch']->id, 'description' => 'Venta directa anterior', 'items' => [['description' => 'Operación directa anterior', 'quantity' => 1, 'unit_price' => '500']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => '2026-12-01']);
        foreach ([['admin', 'workspace.customers'], ['head', 'head-office.overview']] as [$role, $route]) {
            $response = $this->actingAs($data[$role])->get(route($route))->assertOk();
            $this->assertSame(38000, $response->viewData('groups')['dealership']['debt_cents']);
        }
        $this->actingAs($data['admin'])->get(route('clients.index', ['customer_kind' => 'person', 'unassigned' => 1]))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->modelKeys() === [$unassigned->id]);
        $data['receipt']->update(['payment_date' => null, 'created_at' => '2026-10-14 10:00:00']);
        $this->get(route('workspace.sales', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertViewHas('receivedMonthCents', 12000);
    }
}
