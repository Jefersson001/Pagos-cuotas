<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcessionaireStartDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
    }

    public function test_registration_sets_start_date_and_editing_cannot_replace_it(): void
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $values = ['name' => 'Concesionario nuevo', 'active' => 1, 'customer_kind' => 'dealership', 'started_at' => '2020-01-01'];
        $this->actingAs($head)->post(route('clients.store'), $values)->assertSessionHasNoErrors();
        $dealer = Client::where('name', $values['name'])->firstOrFail();
        $this->assertSame('2026-11-16', $dealer->started_at->toDateString());
        $this->assertNull($dealer->affiliation_date);
        $this->travelTo(now()->addDay());
        $this->put(route('clients.update', $dealer), $values)->assertSessionHasNoErrors();
        $this->assertSame('2026-11-16', $dealer->fresh()->started_at->toDateString());
        $this->get(route('clients.edit', $dealer))->assertOk()->assertSee('Fecha de inicio')
            ->assertSee('value="2026-11-16" readonly', false);
    }

    public function test_historical_start_dates_are_synchronized_without_changing_customer_dates(): void
    {
        $dealer = Client::factory()->concessionaire()->create(['created_at' => '2026-01-02 14:00:00']);
        DB::table('clients')->where('id', $dealer->id)->update(['started_at' => '2020-01-01']);
        $client = Client::factory()->create(['started_at' => '2020-01-01', 'affiliation_date' => '2026-05-01']);
        $migration = require database_path('migrations/2026_11_16_100240_synchronize_concessionaire_start_dates.php');
        $migration->up();
        $this->assertSame('2026-01-02', $dealer->fresh()->started_at->toDateString());
        $this->assertSame('2020-01-01', $client->fresh()->started_at->toDateString());
        $this->assertSame('2026-05-01', $client->fresh()->affiliation_date->toDateString());
    }

    public function test_head_office_filters_and_monthly_summaries_use_registration_dates(): void
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $january = Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'created_at' => '2026-01-31 23:59:59']);
        Client::factory()->concessionaire()->create(['dealership_id' => $company->id, 'created_at' => '2026-02-01 00:00:00']);
        Client::factory()->concessionaire()->create(['dealership_id' => Dealership::factory()->create()->id, 'created_at' => '2026-01-31 10:00:00']);
        $this->actingAs($head)->get(route('clients.index', ['started_from' => '2026-01-01', 'started_to' => '2026-01-31']))
            ->assertOk()->assertSee('Inicio desde')->assertSee('Fecha de inicio')
            ->assertViewHas('clients', fn ($clients): bool => $clients->total() === 1 && $clients->first()->id === $january->id);
        foreach (['head-office.overview', 'head-office.sales.overview'] as $route) {
            $this->get(route($route))->assertOk()->assertViewHas('affiliations', fn (array $summary): bool => $summary['dealer_total'] === 2
                && $summary['months'][0]['dealer_count'] === 1 && $summary['months'][1]['dealer_count'] === 1 && $summary['total'] === 0)
                ->assertSee('data-dealer-start-month="2026-01-01"', false);
            $this->get(route($route, ['concessionaire_id' => $january->id]))->assertOk()
                ->assertViewHas('affiliations', fn (array $summary): bool => $summary['dealer_total'] === 1 && $summary['months'][1]['dealer_count'] === 0);
        }
        $this->getJson(route('clients.index', ['started_from' => '2026-02-01', 'started_to' => '2026-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('started_to');
    }
}
