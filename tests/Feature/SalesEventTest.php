<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesEvent;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array<string,mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $branch = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $client = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $event = SalesEvent::factory()->create(['dealership_id' => $company->id, 'name' => 'Evento Motor Extreme']);
        $normal = SalesEvent::defaultForCompany($company->id);
        $customer = User::factory()->create(['role_id' => Role::where('name', 'customer')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $branch->id, 'client_id' => $client->id]);

        return compact('company', 'head', 'branch', 'dealer', 'client', 'event', 'normal', 'customer');
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    private function saleValues(array $data, array $changes = []): array
    {
        return array_replace(['token' => (string) Str::uuid(), 'client_id' => $data['client']->id, 'description' => 'Venta de jornada', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '400']], 'initial_amount' => '0', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()], $changes);
    }

    public function test_managers_create_events_and_only_one_active_normal_remains_per_company(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->post(route('sales-events.store'), ['name' => 'Jornada principal', 'active' => 1, 'is_default' => 1])->assertSessionHasNoErrors();
        $principal = SalesEvent::where('dealership_id', $data['company']->id)->where('name', 'Jornada principal')->firstOrFail();
        $this->assertTrue($principal->is_default);
        $this->assertFalse($data['normal']->fresh()->is_default);
        $this->assertSame(1, SalesEvent::where('dealership_id', $data['company']->id)->where('is_default', true)->count());
        $this->put(route('sales-events.update', $principal), ['name' => 'Normal principal', 'active' => 1, 'is_default' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($principal->fresh()->is_default);
        $this->put(route('sales-events.update', $principal), ['name' => 'Normal principal', 'active' => 0, 'is_default' => 1])->assertSessionHasErrors('is_default');
        $this->put(route('sales-events.update', $principal), ['name' => 'Normal principal', 'active' => 1, 'is_default' => 0])->assertSessionHasErrors('is_default');
        $this->delete(route('sales-events.destroy', $principal))->assertSessionHasErrors('event');
        $this->assertTrue($principal->fresh()->active);
    }

    public function test_events_and_management_are_scoped_and_dealers_can_only_read(): void
    {
        $data = $this->portfolio();
        $other = $this->portfolio();
        $this->actingAs($data['head'])->get(route('sales-events.index'))->assertOk();
        $this->get(route('sales-events.show', $other['event']))->assertForbidden();
        $this->put(route('sales-events.update', $other['event']), ['name' => 'Incorrecto', 'active' => 1])->assertForbidden();
        $this->actingAs($data['dealer'])->get(route('sales-events.index'))->assertOk()->assertDontSee('Nueva jornada');
        $this->get(route('sales-events.show', $data['event']))->assertOk();
        $this->get(route('sales-events.create'))->assertForbidden();
        $this->post(route('sales-events.store'), ['name' => 'No permitido', 'active' => 1])->assertForbidden();
        $this->put(route('sales-events.update', $data['event']), ['name' => 'No permitido', 'active' => 1])->assertForbidden();
        $this->delete(route('sales-events.destroy', $data['event']))->assertForbidden();
        $this->actingAs($data['customer'])->get(route('sales-events.index'))->assertForbidden();
    }

    public function test_event_options_follow_the_client_company_and_exclude_inactive_events(): void
    {
        $data = $this->portfolio();
        $other = $this->portfolio();
        $inactive = SalesEvent::factory()->create(['dealership_id' => $data['company']->id, 'active' => false]);
        $this->actingAs($data['dealer'])->getJson(route('sales-events.options', ['client_id' => $data['client']->id]))->assertOk()->assertJsonPath('default_id', $data['normal']->id)->assertJsonCount(2, 'events')->assertJsonMissing(['id' => $inactive->id]);
        $this->getJson(route('sales-events.options', ['client_id' => $other['client']->id]))->assertNotFound();
        $this->actingAs($data['customer'])->getJson(route('sales-events.options', ['client_id' => $data['client']->id]))->assertForbidden();
    }

    public function test_sales_store_one_event_and_default_to_normal_without_changing_existing_assignments(): void
    {
        $data = $this->portfolio();
        $normalSale = app(SalesService::class)->create($data['dealer'], $this->saleValues($data));
        $eventSale = app(SalesService::class)->create($data['dealer'], $this->saleValues($data, ['sales_event_id' => $data['event']->id]));
        $this->assertSame($data['normal']->id, $normalSale->sales_event_id);
        $this->assertSame($data['event']->id, $eventSale->sales_event_id);
        $this->actingAs($data['dealer'])->get(route('orders.show', $eventSale))->assertOk()->assertSee('Evento Motor Extreme');
        $this->get(route('orders.create', ['client_id' => $data['client']->id]))->assertOk()->assertSee('name="sales_event_id"', false)->assertViewHas('salesEvents', fn ($events) => $events->contains('id', $data['normal']->id));
        $this->actingAs($data['head'])->put(route('sales-events.update', $data['event']), ['name' => $data['event']->name, 'active' => 0, 'is_default' => 0])->assertSessionHasNoErrors();
        $this->assertSame($data['event']->id, $eventSale->fresh()->sales_event_id);
        $this->delete(route('sales-events.destroy', $data['event']))->assertSessionHasErrors('event');
        $this->assertModelExists($data['event']);
    }

    public function test_sales_reject_inactive_foreign_or_multiple_events(): void
    {
        $data = $this->portfolio();
        $other = $this->portfolio();
        $inactive = SalesEvent::factory()->create(['dealership_id' => $data['company']->id, 'active' => false]);
        $product = Product::factory()->create(['user_id' => $data['dealer']->id, 'dealership_id' => $data['company']->id]);
        foreach ([0, $inactive->id, $other['event']->id, [$data['event']->id, $data['normal']->id]] as $eventId) {
            $this->actingAs($data['dealer'])->post(route('orders.store'), $this->saleValues($data, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'sales_event_id' => $eventId]))->assertSessionHasErrors('sales_event_id');
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_event_comparison_excludes_cancelled_sales_and_respects_dates_and_portfolios(): void
    {
        $data = $this->portfolio();
        $otherBranch = Client::factory()->concessionaire()->create(['dealership_id' => $data['company']->id]);
        $otherDealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $data['company']->id, 'concessionaire_id' => $otherBranch->id]);
        $otherClient = Client::factory()->for($otherDealer, 'owner')->create(['dealership_id' => $data['company']->id, 'concessionaire_id' => $otherBranch->id]);
        app(SalesService::class)->create($data['dealer'], $this->saleValues($data, ['sales_event_id' => $data['event']->id]));
        app(SalesService::class)->create($otherDealer, $this->saleValues($data, ['client_id' => $otherClient->id, 'sales_event_id' => $data['event']->id]));
        $cancelled = app(SalesService::class)->create($data['dealer'], $this->saleValues($data, ['sales_event_id' => $data['event']->id]));
        $cancelled->update(['cancelled' => true]);
        $old = app(SalesService::class)->create($data['dealer'], $this->saleValues($data, ['sales_event_id' => $data['event']->id]));
        $old->forceFill(['created_at' => today()->subYear()])->save();
        $assertResults = fn (int $count, int $total) => fn ($events): bool => (int) $events->firstWhere('id', $data['event']->id)->sales_count === $count && (int) $events->firstWhere('id', $data['event']->id)->sales_total === $total;
        $this->actingAs($data['head'])->get(route('sales-events.index'))->assertOk()->assertViewHas('events', $assertResults(2, 800));
        $this->get(route('sales-events.index', ['concessionaire_id' => $data['branch']->id]))->assertOk()->assertViewHas('events', $assertResults(1, 400));
        $this->get(route('sales-events.index', ['from' => today()->subYears(2)->toDateString(), 'to' => today()->toDateString(), 'date_mode' => 'custom']))->assertOk()->assertViewHas('events', $assertResults(3, 1200));
        $this->actingAs($data['dealer'])->get(route('sales-events.index'))->assertOk()->assertViewHas('events', $assertResults(1, 400));
        $this->actingAs($data['head'])->get(route('head-office.orders.index', ['sales_event_id' => $data['event']->id]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 3);
    }

    public function test_admin_can_create_and_delete_an_unused_event_and_company_defaults_are_idempotent(): void
    {
        $data = $this->portfolio();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $this->actingAs($admin)->post(route('sales-events.store'), ['name' => 'Evento sin ventas', 'dealership_id' => $data['company']->id, 'active' => 1, 'is_default' => 0])->assertSessionHasNoErrors();
        $event = SalesEvent::where('dealership_id', $data['company']->id)->where('name', 'Evento sin ventas')->firstOrFail();
        $this->post(route('sales-events.store'), ['name' => 'Evento sin ventas', 'dealership_id' => $data['company']->id, 'active' => 1])->assertSessionHasErrors('name');
        $this->delete(route('sales-events.destroy', $event))->assertSessionHasNoErrors();
        $this->assertModelMissing($event);
        $this->assertSame($data['normal']->id, SalesEvent::defaultForCompany($data['company']->id)->id);
        $this->assertSame(1, SalesEvent::where('dealership_id', $data['company']->id)->where('is_default', true)->count());
    }

    public function test_new_organizations_receive_their_normal_sales_event(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $this->actingAs($admin)->post(route('dealerships.store'), ['name' => 'Empresa jornadas', 'code' => 'EMP-JORNADAS', 'active' => 1, 'organization_type' => 'head_office'])->assertSessionHasNoErrors();
        $company = Dealership::where('code', 'EMP-JORNADAS')->firstOrFail();
        $this->assertDatabaseHas('sales_events', ['dealership_id' => $company->id, 'name' => 'Jornada normal', 'is_default' => true, 'active' => true]);
    }

    public function test_deleted_organizations_do_not_leave_broken_event_rows_in_the_comparison(): void
    {
        $data = $this->portfolio();
        $data['company']->delete();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $this->actingAs($admin)->get(route('sales-events.index'))->assertOk()->assertViewHas('events', fn ($events) => ! $events->contains('id', $data['event']->id));
        $this->get(route('sales-events.show', $data['event']))->assertForbidden();
    }
}
