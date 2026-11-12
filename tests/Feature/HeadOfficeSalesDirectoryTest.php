<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeadOfficeSalesDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function sale(User $admin, Client $client, string $description): Order
    {
        return app(SalesService::class)->create($admin, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => $description, 'items' => [['description' => $description, 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreignCompany = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $north = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $south = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $foreignDealer = Client::factory()->concessionaire()->create(['dealership_id' => $foreignCompany->id]);
        $client = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id]);
        $other = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $south->id]);
        $foreignClient = Client::factory()->create(['dealership_id' => $foreignCompany->id, 'concessionaire_id' => $foreignDealer->id]);
        $orphan = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => null]);
        $order = $this->sale($admin, $client, 'Venta Norte');
        $second = $this->sale($admin, $other, 'Venta Sur');
        $foreign = $this->sale($admin, $foreignClient, 'Venta externa secreta');
        $unassigned = $this->sale($admin, $orphan, 'Venta sin concesionario');
        $old = $this->sale($admin, $client, 'Venta anterior');
        $old->update(['created_at' => today()->subMonthsNoOverflow(2)]);

        return compact('head', 'admin', 'company', 'north', 'south', 'foreignDealer', 'client', 'other', 'foreignClient', 'order', 'second', 'foreign', 'old', 'unassigned', 'orphan');
    }

    public function test_head_office_sales_module_lists_all_its_dealers_sales_read_only(): void
    {
        $data = $this->portfolio();
        $modules = $data['head']->workspaceModules();
        $this->assertSame('Ventas', $modules['payments']['name']);
        $this->assertSame('head-office.sales.overview', $modules['payments']['route']);
        $this->assertSame(['Resumen', 'Ventas', 'Jornadas de venta', 'Pagos'], array_column($modules['payments']['children'], 'name'));
        $this->actingAs($data['head'])->get(route('head-office.orders.index', ['dealership_id' => $data['foreign']->dealership_id]))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 2)
            ->assertSee('Venta Norte')->assertSee('Venta Sur')->assertSee('Solo consulta')->assertSee('Concesionario')
            ->assertSee(route('head-office.orders.show', $data['order']), false)
            ->assertDontSee('Nueva venta')->assertDontSee('>Editar<', false)->assertDontSee('Venta externa secreta')->assertDontSee('Venta sin concesionario')
            ->assertViewHas('clients', fn ($clients) => $clients->modelKeys() === collect([$data['client'], $data['other']])->sortBy('name')->pluck('id')->all())
            ->assertViewHas('concessionaires', fn ($dealers) => $dealers->count() === 2);
    }

    public function test_client_dealer_search_and_date_filters_are_applied_to_the_same_scoped_list(): void
    {
        $data = $this->portfolio();
        foreach ([['client_id' => $data['client']->id], ['concessionaire_id' => $data['north']->id], ['q' => 'Venta Norte']] as $filter) {
            $this->actingAs($data['head'])->get(route('head-office.orders.index', $filter))->assertOk()
                ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$data['order']->id]);
        }
        $this->get(route('head-office.orders.index', ['client_id' => $data['client']->id, 'concessionaire_id' => $data['south']->id]))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->get(route('head-office.orders.index', ['date_mode' => 'custom', 'from' => $data['old']->created_at->toDateString(), 'to' => $data['old']->created_at->toDateString()]))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$data['old']->id]);
        $this->get(route('head-office.orders.index', ['client_id' => $data['foreignClient']->id]))->assertSessionHasErrors('client_id');
        $this->get(route('head-office.orders.index', ['client_id' => $data['orphan']->id]))->assertSessionHasErrors('client_id');
        $this->get(route('head-office.orders.index', ['concessionaire_id' => $data['foreignDealer']->id]))->assertSessionHasErrors('concessionaire_id');
        $this->get(route('head-office.orders.index', ['from' => today()->toDateString(), 'to' => today()->subDay()->toDateString()]))->assertSessionHasErrors('to');
    }

    public function test_read_only_sales_do_not_grant_creation_editing_or_payment_registration(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('head-office.orders.show', $data['order']))->assertOk()->assertDontSee('Registrar pago')->assertDontSee('Duplicar');
        $this->get(route('head-office.orders.show', $data['foreign']))->assertNotFound();
        $this->get(route('orders.create'))->assertForbidden();
        $this->post(route('orders.store'), [])->assertForbidden();
        $this->get(route('orders.edit', $data['order']))->assertForbidden();
        $this->put(route('orders.update', $data['order']), ['notes' => 'Cambio prohibido'])->assertForbidden();
        $this->post(route('orders.pay', $data['order']), [])->assertForbidden();
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $data['company']->id, 'concessionaire_id' => $data['north']->id]);
        $this->actingAs($dealer)->get(route('head-office.orders.index'))->assertForbidden();

    }
}
