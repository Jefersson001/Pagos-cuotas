<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\SalesEvent;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\ReportService;
use App\Services\SalesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
    }

    /** @return array<string,mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $branch = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Concesionario del reporte']);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $client = Client::factory()->for($dealer, 'owner')->create(['name' => 'CLIENTE PRIVADO Álvarez', 'dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Banco PDF', 'active' => true]);
        $values = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta PDF', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '100.01']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => '2026-11-05'];
        $active = app(SalesService::class)->create($admin, $values);
        $paid = app(SalesService::class)->create($admin, array_replace($values, ['token' => (string) Str::uuid()]));
        app(SalesService::class)->pay($admin, $paid->id, ['token' => (string) Str::uuid(), 'amount' => '100.01', 'payment_method_id' => $method->id, 'reference' => 'REFERENCIA APROBADA']);
        $cancelled = app(SalesService::class)->create($admin, array_replace($values, ['token' => (string) Str::uuid()]));
        $cancelled->update(['cancelled' => true]);
        app(ReceiptService::class)->receive($dealer, $client->id, ['token' => (string) Str::uuid(), 'amount' => '12', 'payment_method_id' => $method->id, 'reference' => 'REFERENCIA PENDIENTE']);
        $voided = app(ReceiptService::class)->receive($admin, $client->id, ['token' => (string) Str::uuid(), 'amount' => '8', 'payment_method_id' => $method->id, 'reference' => 'REFERENCIA ANULADA']);
        app(ReceiptService::class)->void($admin, $voided, 'Ingreso de prueba anulado');
        $customer = User::factory()->create(['role_id' => Role::where('name', 'customer')->value('id'), 'dealership_id' => $company->id, 'client_id' => $client->id, 'concessionaire_id' => $branch->id]);

        return compact('company', 'admin', 'head', 'branch', 'dealer', 'client', 'active', 'paid', 'customer', 'values');
    }

    public function test_all_report_print_variants_match_the_data_used_on_screen(): void
    {
        $data = $this->portfolio();
        foreach ([$data['admin'], $data['dealer']] as $actor) {
            $this->actingAs($actor);
            foreach (array_keys(ReportService::TYPES) as $type) {
                $parameters = ['type' => $type, 'from' => '2026-11-01', 'to' => '2026-11-30', 'client' => $data['client']->name];
                $screen = $this->get(route('reports', $parameters))->assertOk();
                $print = $this->get(route('reports', $parameters + ['print' => 1, 'page' => 2]))->assertOk()->assertViewIs('reports.print');
                $this->assertSame($screen->viewData('rows')->items(), $print->viewData('printRows')->all());
                $this->assertSame($screen->viewData('columns'), $print->viewData('columns'));
                $this->assertGreaterThan(0, $print->viewData('total'), $type);
                $print->assertSee(ReportService::TYPES[$type])->assertSee('01/11/2026')->assertSee('30/11/2026')->assertSee('Importes en USD.')->assertDontSee('workspace-header');
            }
        }
    }

    public function test_pdf_keeps_every_record_and_does_not_export_just_the_selected_page(): void
    {
        $data = $this->portfolio();
        for ($number = 0; $number < 23; $number++) {
            app(SalesService::class)->create($data['admin'], array_replace($data['values'], ['token' => (string) Str::uuid()]));
        }
        $this->actingAs($data['admin']);
        $this->get(route('reports', ['type' => 'sales', 'page' => 2]))->assertOk()->assertViewHas('total', 26)->assertViewHas('rows', fn ($rows) => $rows->count() === 6);
        $print = $this->get(route('reports', ['type' => 'sales', 'page' => 2, 'print' => 1]))->assertOk()->assertViewHas('total', 26)->assertViewHas('printRows', fn ($rows) => $rows->count() === 26);
        $this->assertStringNotContainsString('print=', $print->viewData('backUrl'));
        $this->assertStringNotContainsString('page=', $print->viewData('backUrl'));
    }

    public function test_consolidated_pdf_keeps_exact_totals_and_company_scope_without_retail_identities(): void
    {
        $data = $this->portfolio();
        $foreign = $this->portfolio();
        $this->actingAs($data['head']);
        $parameters = ['from' => '2026-11-01', 'to' => '2026-11-30', 'date_mode' => 'custom', 'concessionaire_id' => $data['branch']->id, 'dealership_id' => $foreign['company']->id, 'client' => 'NO DEBE FIGURAR'];
        $screen = $this->get(route('reports', $parameters))->assertOk();
        $print = $this->get(route('reports', $parameters + ['print' => 1]))->assertOk()->assertViewHas('total', 1);
        $this->assertSame($screen->viewData('totals'), $print->viewData('totals'));
        $this->assertSame($screen->viewData('csv_rows')->last(), $print->viewData('footerRow'));
        $this->assertSame($data['company']->name, $print->viewData('printFilters')['Empresa']);
        $print->assertDontSee('CLIENTE PRIVADO')->assertDontSee('NO DEBE FIGURAR');
    }

    public function test_sales_event_pdf_includes_every_event_and_the_same_totals_and_filters(): void
    {
        $data = $this->portfolio();
        SalesEvent::factory()->count(23)->create(['dealership_id' => $data['company']->id]);
        $this->actingAs($data['head']);
        $screen = $this->get(route('sales-events.index'))->assertOk()->assertViewHas('events', fn ($events) => $events->total() === 24 && $events->count() === 20);
        $print = $this->get(route('sales-events.index', ['print' => 1, 'page' => 2]))->assertOk()->assertViewIs('reports.print')->assertViewHas('total', 24)->assertViewHas('printRows', fn ($rows) => $rows->count() === 24);
        $this->assertSame(['Total', '', '', '', 2, '200.02'], $print->viewData('footerRow'));
        $this->get(route('sales-events.index', ['print' => 1, 'q' => 'Jornada normal', 'active' => '1', 'concessionaire_id' => $data['branch']->id]))->assertOk()->assertViewHas('total', 1)->assertSee('Jornada normal')->assertSee($data['branch']->name);
        $this->get(route('sales-events.index', ['print' => 1, 'from' => '2000-01-01', 'to' => '2000-01-02', 'date_mode' => 'custom']))->assertOk()->assertViewHas('footerRow', ['Total', '', '', '', 0, '0.00']);
    }

    public function test_print_outputs_handle_empty_reports_and_reject_invalid_filters_and_customer_access(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealer']);
        foreach (array_keys(ReportService::TYPES) as $type) {
            $this->get(route('reports', ['type' => $type, 'client' => 'Sin coincidencias PDF', 'print' => 1]))->assertOk()->assertSee('Sin resultados para estos filtros.');
        }
        $this->getJson(route('reports', ['print' => 1, 'from' => '2026-12-01', 'to' => '2026-11-01']))->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson(route('reports', ['print' => 1, 'type' => 'incorrecto']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->actingAs($data['customer'])->get(route('reports', ['print' => 1]))->assertForbidden();
        $this->get(route('sales-events.index', ['print' => 1]))->assertForbidden();
    }

    public function test_pdf_filter_summary_never_echoes_a_foreign_company_or_ignored_responsible_for_a_dealer(): void
    {
        $data = $this->portfolio();
        $foreign = $this->portfolio();
        $this->actingAs($data['dealer'])->get(route('reports', ['print' => 1, 'dealership_id' => $foreign['company']->id, 'user_id' => $foreign['dealer']->id]))->assertOk()
            ->assertViewHas('printFilters', fn ($filters) => $filters['Empresa'] === $data['company']->name && $filters['Concesionario'] === $data['branch']->name && ! isset($filters['Responsable']));
    }
}
