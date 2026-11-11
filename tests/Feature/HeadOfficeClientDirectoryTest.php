<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeadOfficeClientDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreignCompany = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $north = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Concesionario Norte']);
        $south = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'name' => 'Concesionario Sur']);
        $foreignDealer = Client::factory()->concessionaire()->create(['dealership_id' => $foreignCompany->id, 'name' => 'Concesionario externo']);
        $client = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id, 'name' => 'Cliente Norte']);
        $other = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $south->id, 'name' => 'Cliente Sur', 'active' => false]);
        $foreign = Client::factory()->create(['dealership_id' => $foreignCompany->id, 'concessionaire_id' => $foreignDealer->id]);
        $orphan = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => null]);
        $mismatched = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $foreignDealer->id]);

        return compact('head', 'company', 'north', 'south', 'client', 'other', 'foreign', 'foreignDealer', 'orphan', 'mismatched');
    }

    public function test_head_office_can_consult_all_its_dealers_customers_without_write_actions(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('head-office.clients', ['dealership_id' => $data['foreign']->dealership_id, 'customer_kind' => 'dealership']))->assertOk()
            ->assertViewHas('clients', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->sort()->values()->all() === collect([$data['client']->id, $data['other']->id])->sort()->values()->all())
            ->assertSee('Concesionario Norte')->assertSee('Concesionario Sur')->assertSee('Solo consulta')
            ->assertDontSee($data['foreign']->name)->assertDontSee('Concesionario externo')->assertDontSee($data['orphan']->name)->assertDontSee($data['mismatched']->name)
            ->assertDontSee('Nuevo cliente')->assertDontSee('Nuevo concesionario')->assertDontSee('>Editar<', false);
        $this->get(route('clients.index'))->assertOk()->assertSee('Nuevo concesionario')->assertSee(route('head-office.clients'), false)
            ->assertViewHas('clients', fn ($rows) => $rows->total() === 2);
    }

    public function test_filters_and_search_are_scoped_to_the_organization(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('head-office.clients', ['concessionaire_id' => $data['north']->id]))->assertOk()
            ->assertViewHas('clients', fn ($rows) => $rows->pluck('id')->all() === [$data['client']->id]);
        $this->get(route('head-office.clients', ['q' => 'Cliente Sur', 'active' => 0]))->assertOk()
            ->assertViewHas('clients', fn ($rows) => $rows->pluck('id')->all() === [$data['other']->id]);
        $this->get(route('head-office.clients', ['concessionaire_id' => $data['north']->id, 'q' => 'Cliente Sur']))->assertOk()
            ->assertViewHas('clients', fn ($rows) => $rows->isEmpty());
        foreach ([$data['foreignDealer']->id, $data['client']->id, 'incorrecto'] as $id) {
            $this->get(route('head-office.clients', ['concessionaire_id' => $id]))->assertSessionHasErrors('concessionaire_id');
        }
    }

    public function test_customer_detail_is_read_only_and_rejects_outside_records_and_writes(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('head-office.clients.show', $data['client']))->assertOk()
            ->assertSee($data['client']->name)->assertSee('Concesionario Norte')->assertSee('Solo consulta')
            ->assertDontSee('Duplicar')->assertDontSee('Eliminar')->assertDontSee('Editar')->assertDontSee('Nueva venta')->assertDontSee('Registrar pago');
        foreach (['foreign', 'orphan', 'mismatched', 'north'] as $key) {
            $this->get(route('head-office.clients.show', $data[$key]))->assertNotFound();
        }
        $this->get(route('clients.edit', $data['client']))->assertForbidden();
        $this->put(route('clients.update', $data['client']), ['name' => 'Cambio prohibido'])->assertForbidden();
        $this->post(route('clients.store'), ['customer_kind' => 'person', 'name' => 'No crear'])->assertSessionHasErrors('customer_kind');
        $this->delete(route('records.destroy', ['type' => 'client', 'id' => $data['client']->id]))->assertForbidden();
        $this->assertSame('Cliente Norte', $data['client']->fresh()->name);
    }

    public function test_other_roles_cannot_use_the_head_office_directory(): void
    {
        $data = $this->portfolio();
        foreach (['dealership_admin', 'customer'] as $role) {
            $user = User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $data['company']->id, 'concessionaire_id' => $data['north']->id, 'client_id' => $role === 'customer' ? $data['client']->id : null]);
            $this->actingAs($user)->get(route('head-office.clients'))->assertForbidden();
            $this->get(route('head-office.clients.show', $data['client']))->assertForbidden();
        }

    }

    public function test_head_office_sees_customer_financial_tabs_and_details_without_financial_controls(): void
    {
        $data = $this->portfolio();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $data['company']->id, 'concessionaire_id' => $data['north']->id]);
        $method = PaymentMethod::create(['name' => 'Transferencia de prueba', 'dealership_id' => $data['company']->id, 'active' => true]);
        $saleData = ['token' => (string) Str::uuid(), 'client_id' => $data['client']->id, 'description' => 'Compra consultada por matriz', 'items' => [['description' => 'Producto de prueba', 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '20.00', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()];
        $order = app(SalesService::class)->create($admin, $saleData);
        $service = app(ReceiptService::class);
        $receiptData = ['token' => (string) Str::uuid(), 'amount' => '50.00', 'payment_method_id' => $method->id, 'reference' => 'REFERENCIA-VISIBLE'];
        $receipt = $service->receive($admin, $data['client']->id, $receiptData);
        $service->apply($admin, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => '20.00']);
        $pending = $service->receive($dealer, $data['client']->id, array_replace($receiptData, ['token' => (string) Str::uuid(), 'amount' => '10.00']));
        $rejected = $service->receive($dealer, $data['client']->id, array_replace($receiptData, ['token' => (string) Str::uuid(), 'amount' => '15.00']));
        $service->reject($data['head'], $rejected, 'Comprobante no confirmado');

        $this->actingAs($data['head'])->get(route('head-office.clients.show', $data['client']))->assertOk()
            ->assertSee('data-tab-panel="Resumen"', false)->assertSee('data-tab-panel="Datos de contacto"', false)
            ->assertSee('data-tab-panel="Ventas"', false)->assertSee('data-tab-panel="Pagos"', false)
            ->assertSee('<small>Deuda de ventas</small><strong>$80.00</strong>', false)
            ->assertSee('<small>Pagos recibidos confirmados</small><strong>$50.00</strong>', false)
            ->assertSee('<small>Saldo a favor sin aplicar</small><strong>$30.00</strong>', false)
            ->assertSee(route('head-office.orders.show', $order), false)->assertSee(route('head-office.receipts.show', $receipt), false)
            ->assertSee('Transferencia de prueba')->assertDontSee('Nueva venta')->assertDontSee('Registrar pago');
        $this->get(route('head-office.orders.show', $order))->assertOk()->assertSee('Compromisos de pago')->assertSee('Producto de prueba')
            ->assertDontSee('payment-dialog')->assertDontSee('Registrar pago')->assertDontSee('Usar saldo a favor')->assertDontSee('Duplicar')->assertDontSee('Eliminar registro');
        foreach ([$receipt, $pending, $rejected] as $payment) {
            $this->get(route('head-office.receipts.show', $payment))->assertOk()->assertSee('Historial del pago')->assertSee('Comprobante del cliente')->assertSee('REFERENCIA-VISIBLE')
                ->assertDontSee('Aprobar pago')->assertDontSee('Rechazar pago')->assertDontSee('Anular ingreso de dinero')->assertDontSee('Aplicar pago');
        }
        $this->get(route('clients.show', $data['north']))->assertOk()->assertSee(route('head-office.clients', ['concessionaire_id' => $data['north']->id]), false);
        $this->get(route('orders.edit', $order))->assertForbidden();
        $this->post(route('orders.pay', $order), [])->assertForbidden();
        $this->assertSame(8000, $order->fresh()->balance_cents);
        $this->assertSame(3000, $data['client']->fresh()->credit_cents);

        $foreignOrder = app(SalesService::class)->create($admin, array_replace($saleData, ['token' => (string) Str::uuid(), 'client_id' => $data['foreign']->id]));
        $foreignMethod = PaymentMethod::create(['name' => 'Cuenta externa', 'dealership_id' => $data['foreign']->dealership_id, 'active' => true]);
        $foreignReceipt = $service->receive($admin, $data['foreign']->id, array_replace($receiptData, ['token' => (string) Str::uuid(), 'payment_method_id' => $foreignMethod->id]));
        $this->get(route('head-office.orders.show', $foreignOrder))->assertNotFound();
        $this->get(route('head-office.receipts.show', $foreignReceipt))->assertNotFound();
    }
}
