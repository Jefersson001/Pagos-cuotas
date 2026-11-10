<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actor(string $role, ?Dealership $company = null, ?Client $contact = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company?->id, 'concessionaire_id' => $contact?->id]);
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreignCompany = Dealership::factory()->create(['organization_type' => 'head_office']);
        $admin = $this->actor('admin');
        $head = $this->actor('head_office', $company);
        $contactA = Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'Concesionario A', 'dealership_id' => $company->id]);
        $contactB = Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'Concesionario B', 'dealership_id' => $company->id]);
        $foreignContact = Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'Concesionario ajeno', 'dealership_id' => $foreignCompany->id]);
        $dealerA = $this->actor('dealership_admin', $company, $contactA);
        $dealerB = $this->actor('dealership_admin', $company, $contactB);
        $clientA = Client::factory()->for($admin, 'owner')->create(['name' => 'Comprador privado A', 'dealership_id' => $company->id, 'concessionaire_id' => $contactA->id]);
        $clientB = Client::factory()->for($admin, 'owner')->create(['name' => 'Comprador privado B', 'dealership_id' => $company->id, 'concessionaire_id' => $contactB->id]);
        $orphan = Client::factory()->for($admin, 'owner')->create(['name' => 'Comprador sin concesionario', 'dealership_id' => $company->id]);
        $method = PaymentMethod::create(['name' => 'Efectivo de la organización', 'active' => true, 'dealership_id' => $company->id]);
        $sales = app(SalesService::class);
        $data = ['token' => (string) Str::uuid(), 'client_id' => $clientA->id, 'description' => 'Venta de prueba', 'items' => [['description' => 'Compra privada', 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '20.00', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()];
        $orderA = $sales->create($admin, $data);
        $data['token'] = (string) Str::uuid();
        $data['client_id'] = $clientB->id;
        $data['items'][0]['unit_price'] = '200.00';
        $orderB = $sales->create($admin, $data);
        $receipts = app(ReceiptService::class);
        $receiptA = $receipts->receive($admin, $clientA->id, ['token' => (string) Str::uuid(), 'amount' => '40.00', 'payment_method_id' => $method->id]);
        $receiptB = $receipts->receive($admin, $clientB->id, ['token' => (string) Str::uuid(), 'amount' => '50.00', 'payment_method_id' => $method->id]);
        $paymentA = $receipts->apply($admin, $receiptA, $orderA->id, ['token' => (string) Str::uuid(), 'amount' => '20.00']);

        return compact('company', 'admin', 'head', 'contactA', 'contactB', 'foreignContact', 'dealerA', 'dealerB', 'clientA', 'clientB', 'orphan', 'method', 'orderA', 'orderB', 'receiptA', 'receiptB', 'paymentA');
    }

    public function test_dealers_in_the_same_organization_have_separate_clients_orders_receipts_and_lookups(): void
    {
        $data = $this->portfolio();
        $actor = $data['dealerA'];
        $this->assertSame([$data['clientA']->id], Client::visibleTo($actor)->pluck('id')->all());
        $this->assertSame([$data['orderA']->id], Order::visibleTo($actor)->pluck('id')->all());
        $this->assertSame([$data['receiptA']->id], Receipt::visibleTo($actor)->pluck('id')->all());
        $this->assertSame([$data['paymentA']->id], Payment::visibleTo($actor)->pluck('id')->all());
        $this->assertFalse(Gate::forUser($actor)->allows('view', $data['clientB']));
        $this->actingAs($actor)->get(route('clients.index', ['customer_kind' => 'dealership']))->assertOk()->assertSee('Comprador privado A')->assertDontSee('Comprador privado B')->assertDontSee('Comprador sin concesionario');
        $this->get(route('orders.client-options'))->assertOk()->assertJsonPath('clients.0.id', $data['clientA']->id)->assertJsonCount(1, 'clients');
        $this->get(route('clients.show', $data['clientA']))->assertOk();
        foreach ([['clients.show', $data['clientB']], ['clients.edit', $data['clientB']], ['clients.show', $data['contactB']], ['orders.show', $data['orderB']], ['orders.edit', $data['orderB']], ['receipts.show', $data['receiptB']], ['receipts.edit', $data['receiptB']]] as [$route, $record]) {
            $this->get(route($route, $record))->assertForbidden();
        }
        $this->get(route('receipts.account', ['client_id' => $data['clientB']->id]))->assertForbidden();
        $this->get(route('reports', ['type' => 'sales']))->assertOk()->assertDontSee('Comprador privado B');
    }

    public function test_forged_writes_cannot_cross_portfolios_and_new_clients_get_the_parent_automatically(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealerA'])->post(route('clients.store'), ['name' => 'Cliente automático'])->assertSessionHasNoErrors();
        $created = Client::where('name', 'Cliente automático')->firstOrFail();
        $this->assertSame($data['contactA']->id, $created->concessionaire_id);
        $this->assertSame($data['company']->id, $created->dealership_id);
        $this->put(route('clients.update', $data['clientA']), ['name' => 'Cliente administrado'])->assertSessionHasNoErrors();
        $this->assertSame($data['admin']->id, $data['clientA']->fresh()->user_id);
        $this->post(route('clients.store'), ['name' => 'Intruso', 'concessionaire_id' => $data['contactB']->id])->assertSessionHasErrors('concessionaire_id');
        $this->post(route('clients.store'), ['name' => 'Intruso', 'customer_kind' => 'dealership'])->assertSessionHasErrors('customer_kind');
        $this->post(route('receipts.store'), ['token' => (string) Str::uuid(), 'client_id' => $data['clientB']->id, 'amount' => '10.00', 'payment_method_id' => $data['method']->id])->assertForbidden();
        $product = Product::factory()->create(['dealership_id' => $data['company']->id]);
        $this->post(route('orders.store'), ['token' => (string) Str::uuid(), 'client_id' => $data['clientB']->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()])->assertForbidden();
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('receipts', 2);
        $this->assertDatabaseMissing('clients', ['name' => 'Intruso']);
    }

    public function test_head_office_lists_only_its_dealer_contacts_and_cannot_operate_financial_records(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('clients.index'))->assertOk()->assertSee('Concesionario A')->assertSee('Concesionario B')->assertDontSee('Concesionario ajeno')->assertDontSee('Comprador privado')->assertDontSee('Nuevo cliente')->assertSee('Nuevo concesionario');
        $response = $this->get(route('head-office.overview', ['dealership_id' => $data['foreignContact']->dealership_id]))->assertOk()->assertDontSee('Comprador privado')->assertDontSee('Concesionario ajeno');
        $rows = $response->viewData('rows');
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows->sum('clients'));
        $this->assertSame(30000, $rows->sum('sold_cents'));
        $this->assertSame(9000, $rows->sum('received_cents'));
        $this->assertSame(28000, $rows->sum('debt_cents'));
        $this->assertSame(7000, $rows->sum('credit_cents'));
        $this->get(route('clients.show', $data['contactA']))->assertOk()->assertSee('Ficha del concesionario')->assertSee('Resultados del concesionario')->assertDontSee('Comprador privado')->assertDontSee('Nueva venta')->assertDontSee('Registrar pago')->assertDontSee('Eliminar registro');
        $this->get(route('clients.edit', $data['contactA']))->assertOk();
        foreach ([['clients.show', $data['clientA']], ['clients.show', $data['foreignContact']], ['orders.show', $data['orderA']]] as [$route, $record]) {
            $this->get(route($route, $record))->assertForbidden();
        }
        $this->post(route('clients.store'), ['name' => 'No permitido', 'customer_kind' => 'person'])->assertSessionHasErrors('customer_kind');
        $this->post(route('orders.store'), [])->assertForbidden();
        $this->post(route('receipts.store'), [])->assertForbidden();
        $this->assertTrue(Gate::forUser($data['head'])->allows('update', $data['contactA']));
    }

    public function test_deactivating_or_moving_the_assigned_dealer_revokes_access(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealerA'])->get(route('clients.index'))->assertOk();
        $data['contactA']->update(['active' => false]);
        $this->get(route('clients.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        $data['contactA']->update(['active' => true, 'dealership_id' => $data['foreignContact']->dealership_id]);
        $this->assertFalse($data['dealerA']->fresh()->hasActiveAccess());
    }

    public function test_unassigned_dealer_in_a_shared_organization_can_only_see_its_own_records(): void
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $actor = $this->actor('dealership_admin', $company);
        $own = Client::factory()->for($actor, 'owner')->create(['dealership_id' => $company->id]);
        $other = Client::factory()->create(['dealership_id' => $company->id]);
        $this->assertSame([$own->id], Client::visibleTo($actor)->pluck('id')->all());
        $this->assertFalse(Gate::forUser($actor)->allows('view', $other));
    }
}
