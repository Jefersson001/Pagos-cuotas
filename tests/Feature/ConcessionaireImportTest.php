<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\ConcessionaireImport;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ConcessionaireImportTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);
    }

    private function workbook(array $records, bool $date1904 = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bera-import-');
        $this->files[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><workbookPr date1904="'.($date1904 ? '1' : '0').'"/></workbook>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Sucursal </t></r><r><t>Norte</t></r></si></sst>');
        $rows = [2 => ['C' => 'CLIENTE', 'R' => 'RIF']] + $records;
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $number => $cells) {
            $xml .= '<row r="'.$number.'">';
            foreach ($cells as $column => $value) {
                $ref = $column.$number;
                $xml .= $value === '@shared' ? '<c r="'.$ref.'" t="s"><v>0</v></c>' : '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    public function test_excel_import_preserves_branches_workshops_dates_and_is_repeatable(): void
    {
        $owner = $this->owner();
        $rows = [3 => ['A' => 1, 'B' => 'CONCESIONARIO', 'C' => '@shared', 'G' => 'ACTIVO', 'R' => 'J-123', 'P' => 'Caracas', 'M' => '46163', 'F' => '2026-01-02', 'N' => 'Representante Uno', 'S' => '0412 / 0414', 'D' => 2], 4 => ['A' => 2, 'B' => 'CONCESIONARIO', 'C' => 'Sucursal Sur', 'R' => 'J-123', 'P' => 'Valencia'], 5 => ['A' => 3, 'B' => 'TALLER', 'C' => 'Taller Central', 'R' => 'J-456']];
        $path = $this->workbook($rows);
        $service = app(ConcessionaireImport::class);
        $this->assertSame(['created' => 3, 'updated' => 0, 'total' => 3], $service->import($path, $owner, $owner->dealership_id));
        $north = Client::where('import_reference', 'BERA-CARS-0001')->firstOrFail();
        $this->assertSame('Sucursal Norte', $north->name);
        $this->assertSame('2026-05-21', $north->started_at->format('Y-m-d'));
        $this->assertSame('2026-01-02', $north->listed_started_at->format('Y-m-d'));
        $this->assertSame('Representante Uno', $north->legal_representative);
        $this->assertSame('0412 / 0414', $north->phone);
        $this->assertSame(2, $north->refrigerators);
        $this->assertDatabaseCount('clients', 3);
        $this->assertSame(2, Client::where('document', 'J-123')->count());
        $this->assertSame(1, Client::where('business_type', 'TALLER')->count());
        $rows[3]['S'] = '04129999999';
        $this->assertSame(['created' => 0, 'updated' => 3, 'total' => 3], $service->import($this->workbook($rows), $owner, $owner->dealership_id));
        $this->assertSame('04129999999', $north->fresh()->phone);
        $this->assertDatabaseCount('clients', 3);
    }

    public function test_preview_makes_no_changes_and_invalid_rows_do_not_partially_import(): void
    {
        $owner = $this->owner();
        $rows = [3 => ['A' => 1, 'B' => 'TALLER', 'C' => 'Empresa', 'M' => '1']];
        $path = $this->workbook($rows, true);
        $this->artisan('concesionarios:importar', ['archivo' => $path, '--user' => $owner->id, '--dealership' => $owner->dealership_id, '--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseCount('clients', 0);
        app(ConcessionaireImport::class)->import($path, $owner, $owner->dealership_id);
        $this->assertSame('1904-01-02', Client::firstOrFail()->started_at->format('Y-m-d'));
        $rows[4] = ['A' => 2, 'C' => 'Fecha inválida', 'M' => '31/02/2026'];
        $this->artisan('concesionarios:importar', ['archivo' => $this->workbook($rows), '--user' => $owner->id, '--dealership' => $owner->dealership_id])->assertExitCode(1);
        $this->assertDatabaseCount('clients', 1);
        $this->artisan('concesionarios:importar', ['archivo' => $path, '--user' => $owner->id, '--dealership' => Dealership::factory()->create()->id])->assertExitCode(1);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_import_keeps_existing_orders_receipts_and_credit_unchanged(): void
    {
        $owner = $this->owner();
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Registro anterior', 'document' => 'OLD', 'phone' => '0412']);
        $order = app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Histórico', 'items' => [['description' => 'Carro', 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '0', 'installment_count' => 5, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);
        $method = PaymentMethod::where('dealership_id', $client->dealership_id)->firstOrFail();
        $receipt = app(ReceiptService::class)->receive($owner, $client->id, ['token' => (string) Str::uuid(), 'amount' => '30.00', 'payment_method_id' => $method->id]);
        app(ReceiptService::class)->apply($owner, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => '10.00']);
        app(ConcessionaireImport::class)->import($this->workbook([3 => ['A' => 1, 'C' => 'Nueva empresa']]), $owner, $owner->dealership_id);
        $this->assertSame(9000, $order->fresh()->balance_cents);
        $this->assertSame(2000, $receipt->fresh()->available_cents);
        $this->assertSame($client->id, $order->fresh()->client_id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_single_concessionaire_module_edits_commercial_fields_and_preserves_tenant_security(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $data = ['name' => 'Sucursal A', 'document' => 'J-123', 'phone' => '', 'active' => 1, 'business_type' => 'CONCESIONARIO', 'legal_representative' => 'Representante', 'city' => 'Caracas', 'state' => 'Distrito Capital', 'started_at' => '2026-01-01'];
        $this->post(route('clients.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('clients.store'), array_merge($data, ['name' => 'Sucursal B', 'city' => 'Valencia']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('clients', 2);
        $record = Client::firstOrFail();
        $this->assertStringContainsString('/concesionarios', route('clients.index'));
        $this->get(route('clients.index'))->assertOk()->assertViewHas('clients', fn ($rows) => $rows->total() === 2)->assertSee('Concesionarios');
        $this->get(route('clients.show', $record))->assertOk()->assertSee('Representante')->assertSee('Caracas');
        $other = $this->owner();
        $this->actingAs($other)->get(route('clients.edit', $record))->assertForbidden();
        $this->get(route('clients.index'))->assertViewHas('clients', fn ($rows) => $rows->total() === 0);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $this->actingAs($admin)->get(route('dealerships.index'))->assertRedirect(route('clients.index'));
        $this->get('/clients')->assertRedirect(route('clients.index'));
    }

    public function test_form_only_exposes_spreadsheet_columns_and_keeps_management_data_automatic(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $response = $this->actingAs($admin)->get(route('clients.create'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $fields = [];
        foreach ($xpath->query('//form[contains(@class,"form-card")]//*[@name]') as $field) {
            if (! str_starts_with($field->getAttribute('name'), '_')) {
                $fields[] = $field->getAttribute('name');
            }
        }
        $this->assertSame(['business_type', 'name', 'condition', 'started_at', 'legal_representative', 'address', 'city', 'state', 'document', 'phone', 'email'], $fields);
        $this->post(route('clients.store'), ['name' => 'Empresa del listado', 'document' => 'J-0001', 'condition' => 'ACTIVO'])->assertSessionHasNoErrors();
        $record = Client::firstOrFail();
        $this->assertSame($owner->id, $record->user_id);
        $this->assertTrue($record->active);
        $record->update(['started_at' => '2026-05-21']);
        $details = $this->get(route('clients.show', $record))->assertOk()->assertSee('21/05/2026');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$details->getContent());
        $labels = [];
        foreach ((new \DOMXPath($document))->query('//*[@data-concessionaire-details]//small') as $label) {
            $labels[] = $label->textContent;
        }
        $this->assertSame(['Tipo', 'Cliente', 'Condición', 'Fecha de inicio', 'Representante legal', 'Dirección', 'Ciudad', 'Estado', 'RIF', 'Teléfono', 'Correo'], $labels);
        $record->update(['whatsapp' => '584121234567', 'notes' => 'Histórico']);
        $this->put(route('clients.update', $record), ['name' => 'Empresa actualizada', 'condition' => 'INACTIVO'])->assertSessionHasNoErrors();
        $this->assertSame($owner->id, $record->fresh()->user_id);
        $this->assertSame('584121234567', $record->fresh()->whatsapp);
        $this->assertSame('Histórico', $record->fresh()->notes);
        $this->assertFalse($record->fresh()->active);
    }
}
