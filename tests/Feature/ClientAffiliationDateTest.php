<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientAffiliationDateTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actor = User::factory()->create([
            'role_id' => Role::where('name', 'user')->value('id'),
            'dealership_id' => Dealership::firstOrFail()->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function saleValues(Client $client): array
    {
        return [
            'token' => (string) Str::uuid(), 'client_id' => $client->id,
            'description' => 'Venta de prueba',
            'items' => [['description' => 'Vehiculo', 'quantity' => 1, 'unit_price' => '1000.00']],
            'initial_amount' => '100.00', 'installment_count' => 3,
            'frequency' => 'monthly', 'first_due' => today()->addMonth()->toDateString(),
        ];
    }

    public function test_customer_creation_leaves_affiliation_empty_and_ignores_submitted_dates(): void
    {
        $this->actingAs($this->actor)->post(route('clients.store'), [
            'name' => 'Cliente sin ventas', 'active' => 1, 'customer_kind' => 'person',
            'affiliation_date' => '2020-01-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $client = Client::where('name', 'Cliente sin ventas')->firstOrFail();
        $this->assertNull($client->affiliation_date);
        $this->get(route('clients.edit', $client))->assertOk()
            ->assertSee('Fecha de afiliación')->assertSee('type="date" value="" readonly', false);
    }

    public function test_first_sale_uses_its_registration_date_and_later_sales_preserve_it(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 10)->setTime(10, 0));
        $client = Client::factory()->for($this->actor, 'owner')->create();
        $this->assertNull($client->affiliation_date);

        $this->travelTo(now()->setDate(2026, 3, 5));
        $values = $this->saleValues($client);
        $order = app(SalesService::class)->create($this->actor, $values);
        $this->assertSame('2026-03-05', $client->fresh()->affiliation_date->toDateString());
        $this->assertTrue($order->payments()->doesntExist());

        $this->travelTo(now()->setDate(2026, 4, 7));
        $retry = app(SalesService::class)->create($this->actor, $values);
        $this->assertSame($order->id, $retry->id);
        app(SalesService::class)->create($this->actor, $this->saleValues($client));
        $this->assertSame('2026-03-05', $client->fresh()->affiliation_date->toDateString());
        $this->actingAs($this->actor)->get(route('clients.show', $client))->assertOk()
            ->assertSee('Fecha de afiliación')->assertSee('05/03/2026');
    }

    public function test_editing_customer_cannot_replace_automatic_affiliation_date(): void
    {
        $client = Client::factory()->for($this->actor, 'owner')->create(['affiliation_date' => '2026-02-15']);
        $this->actingAs($this->actor)->put(route('clients.update', $client), [
            'name' => $client->name, 'active' => 1, 'customer_kind' => 'person',
            'affiliation_date' => '2026-11-16',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-02-15', $client->fresh()->affiliation_date->toDateString());
    }

    public function test_invalid_sale_does_not_affiliate_customer(): void
    {
        $client = Client::factory()->for($this->actor, 'owner')->create();
        $values = array_replace($this->saleValues($client), ['initial_amount' => '2000.00']);
        try {
            app(SalesService::class)->create($this->actor, $values);
            $this->fail('An invalid sale must fail validation.');
        } catch (ValidationException) {
            $this->assertNull($client->fresh()->affiliation_date);
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_affiliation_and_sale_roll_back_together(): void
    {
        $client = Client::factory()->for($this->actor, 'owner')->create();
        DB::beginTransaction();
        app(SalesService::class)->create($this->actor, $this->saleValues($client));
        $this->assertNotNull($client->fresh()->affiliation_date);
        DB::rollBack();
        $this->assertNull($client->fresh()->affiliation_date);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_dealer_contacts_never_receive_or_display_customer_affiliation(): void
    {
        $dealer = Client::factory()->concessionaire()->for($this->actor, 'owner')->create();
        app(SalesService::class)->create($this->actor, $this->saleValues($dealer));
        $this->assertNull($dealer->fresh()->affiliation_date);
        $this->actingAs($this->actor)->get(route('clients.show', $dealer))->assertOk()
            ->assertDontSee('Fecha de afiliación');
    }

    public function test_migration_backfills_only_customers_from_their_earliest_registered_sale(): void
    {
        $client = Client::factory()->for($this->actor, 'owner')->create();
        $unsold = Client::factory()->for($this->actor, 'owner')->create();
        $dealer = Client::factory()->concessionaire()->for($this->actor, 'owner')->create();
        foreach ([$client, $dealer] as $contact) {
            $later = app(SalesService::class)->create($this->actor, $this->saleValues($contact));
            $earlier = app(SalesService::class)->create($this->actor, $this->saleValues($contact));
            $later->update(['created_at' => '2026-05-01 10:00:00']);
            $earlier->update(['created_at' => '2026-02-15 12:00:00']);
        }
        $migration = require database_path('migrations/2026_11_16_085807_add_affiliation_date_to_clients_table.php');
        $migration->down();
        $migration->up();
        $this->assertSame('2026-02-15', $client->fresh()->affiliation_date->toDateString());
        $this->assertNull($unsold->fresh()->affiliation_date);
        $this->assertNull($dealer->fresh()->affiliation_date);
        $this->assertDatabaseCount('orders', 4);
    }
}
