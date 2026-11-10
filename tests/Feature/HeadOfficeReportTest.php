<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeadOfficeReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(12, 0));
    }

    private function sale(User $actor, Client $client, string $amount, string $createdAt, string $due = '2026-12-01'): Order
    {
        $order = app(SalesService::class)->create($actor, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'OPERACIÓN PRIVADA', 'items' => [['description' => 'Producto privado', 'quantity' => 1, 'unit_price' => $amount]], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => $due]);
        $order->update(['created_at' => $createdAt]);

        return $order;
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $a = Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'Concesionario Norte', 'dealership_id' => $company->id]);
        Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'Concesionario sin movimientos', 'dealership_id' => $company->id]);
        $b = Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'Concesionario Sur', 'dealership_id' => $company->id]);
        $foreign = Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'CONCESIONARIO AJENO', 'dealership_id' => Dealership::factory()->create()->id]);
        $clientA = Client::factory()->for($admin, 'owner')->create(['name' => 'COMPRADOR PRIVADO NORTE', 'dealership_id' => $company->id, 'concessionaire_id' => $a->id]);
        $clientB = Client::factory()->for($admin, 'owner')->create(['name' => 'COMPRADOR PRIVADO SUR', 'dealership_id' => $company->id, 'concessionaire_id' => $b->id]);
        $old = $this->sale($admin, $clientA, '100.01', '2026-10-10 12:00:00', '2026-10-01');
        $this->sale($admin, $clientA, '200.02', '2026-11-01 00:00:00');
        $this->sale($admin, $clientB, '300.03', '2026-11-08 23:59:59');
        $this->sale($admin, $clientA, '900', '2026-11-05 12:00:00')->update(['cancelled' => true]);
        $this->sale($admin, $clientA, '999', '2026-12-01 12:00:00');
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Efectivo del reporte', 'active' => true]);
        $receipts = app(ReceiptService::class);
        $oldReceipt = $receipts->receive($admin, $clientA->id, ['token' => (string) Str::uuid(), 'amount' => '50', 'payment_method_id' => $method->id]);
        $oldReceipt->update(['created_at' => '2026-10-15 12:00:00']);
        $receipts->apply($admin, $oldReceipt, $old->id, ['token' => (string) Str::uuid(), 'amount' => '20']);
        foreach ([[$clientA, '60.01', '2026-11-01 00:00:00'], [$clientB, '70.02', '2026-11-08 23:59:59']] as [$client, $amount, $date]) {
            $receipt = $receipts->receive($admin, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id]);
            $receipt->update(['created_at' => $date]);
        }
        $voided = $receipts->receive($admin, $clientA->id, ['token' => (string) Str::uuid(), 'amount' => '700', 'payment_method_id' => $method->id]);
        $receipts->void($admin, $voided, 'Pago anulado para verificar el reporte');
        $this->actingAs($head);

        return compact('company', 'admin', 'head', 'a', 'b', 'foreign', 'clientA');
    }

    public function test_report_consolidates_only_own_dealers_with_exact_totals_and_no_retail_identities(): void
    {
        $data = $this->portfolio();
        $response = $this->get(route('reports'))->assertOk()->assertViewIs('reports.head-office')->assertSee('Consolidado por concesionario')->assertSee('Concesionario sin movimientos')->assertDontSee('COMPRADOR PRIVADO')->assertDontSee('OPERACIÓN PRIVADA')->assertDontSee('CONCESIONARIO AJENO');
        $this->assertSame(3, $response->viewData('total'));
        $this->assertSame(['clients' => 2, 'sales_month' => 2, 'sold_cents' => 50005, 'received_cents' => 13003, 'debt_cents' => 58006, 'overdue_cents' => 8001, 'credit_cents' => 16003], $response->viewData('totals'));
        $this->assertSame('2026-11-01', $response->viewData('from'));
        $this->assertSame('2026-11-09', $response->viewData('to'));
        $this->get(route('reports', ['concessionaire_id' => $data['a']->id]))->assertOk()->assertViewHas('total', 1)->assertViewHas('totals', fn ($sums) => $sums['sold_cents'] === 20002 && $sums['debt_cents'] === 28003);
    }

    public function test_date_filters_apply_to_movements_while_balances_remain_current(): void
    {
        $this->portfolio();
        $this->get(route('reports', ['from' => '2026-10-01', 'to' => '2026-10-31', 'date_mode' => 'custom']))->assertOk()->assertViewHas('totals', fn ($totals) => $totals['sold_cents'] === 10001 && $totals['sales_month'] === 1 && $totals['received_cents'] === 5000 && $totals['debt_cents'] === 58006 && $totals['credit_cents'] === 16003);
        $this->get(route('reports', ['from' => '2026-11-08', 'to' => '2026-11-08', 'date_mode' => 'custom']))->assertOk()->assertViewHas('totals', fn ($totals) => $totals['sold_cents'] === 30003 && $totals['received_cents'] === 7002);
    }

    public function test_foreign_contacts_people_and_invalid_dates_cannot_be_used_as_filters(): void
    {
        $data = $this->portfolio();
        foreach ([$data['foreign']->id, $data['clientA']->id, 999999] as $id) {
            $this->getJson(route('reports', ['concessionaire_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('concessionaire_id');
        }
        $this->getJson(route('reports', ['from' => '2026-11-09', 'to' => '2026-11-01']))->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson(route('reports', ['from' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_csv_and_print_export_only_consolidated_data_and_include_total(): void
    {
        $data = $this->portfolio();
        $response = $this->get(route('reports', ['export' => 'csv', 'type' => 'payments', 'client_id' => $data['clientA']->id, 'dealership_id' => $data['foreign']->dealership_id]))->assertOk()->assertDownload('reporte-concesionarios.csv');
        $csv = $response->streamedContent();
        foreach (['Concesionario Norte', '500.05', '580.06', 'Total'] as $value) {
            $this->assertStringContainsString($value, $csv);
        }
        foreach (['COMPRADOR PRIVADO', 'OPERACIÓN PRIVADA', 'CONCESIONARIO AJENO'] as $private) {
            $this->assertStringNotContainsString($private, $csv);
        }
        $this->get(route('reports', ['print' => 1]))->assertOk()->assertSee('Consolidado por concesionario')->assertDontSee('COMPRADOR PRIVADO');
    }

    public function test_export_includes_all_pages_and_protects_spreadsheet_cells(): void
    {
        $data = $this->portfolio();
        Client::factory()->count(20)->concessionaire()->for($data['admin'], 'owner')->create(['dealership_id' => $data['company']->id]);
        Client::factory()->concessionaire()->for($data['admin'], 'owner')->create(['name' => '=1+1', 'document' => '@command', 'dealership_id' => $data['company']->id]);
        $this->get(route('reports'))->assertOk()->assertViewHas('total', 24)->assertViewHas('rows', fn ($rows) => $rows->count() === 20);
        $csv = $this->get(route('reports', ['export' => 'csv', 'page' => 2]))->assertOk()->streamedContent();
        $this->assertCount(26, preg_split('/\r?\n/', trim($csv)));
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'@command", $csv);
        $this->get(route('reports', ['print' => 1]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 24);
    }

    public function test_report_requires_login_and_empty_organization_shows_zero_totals(): void
    {
        $this->get(route('reports'))->assertRedirect(route('login'));
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $this->actingAs($head)->get(route('reports'))->assertOk()->assertViewHas('total', 0)->assertViewHas('totals', fn ($totals) => array_sum($totals) === 0)->assertSee('No hay concesionarios para estos filtros.');
    }
}
