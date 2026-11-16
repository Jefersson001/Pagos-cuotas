<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClientAffiliationOverviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $portfolio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreign = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $north = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $south = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $north->id]);
        $otherDealer = User::factory()->create(['role_id' => $dealer->role_id, 'dealership_id' => $company->id, 'concessionaire_id' => $south->id]);
        $january = Client::factory()->for($dealer, 'owner')->count(2)->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id, 'affiliation_date' => '2026-01-31', 'created_at' => '2025-12-15', 'active' => false]);
        $february = Client::factory()->for($otherDealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $south->id, 'affiliation_date' => '2026-02-01']);
        $unaffiliated = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id]);
        Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id, 'affiliation_date' => '2025-04-01']);
        Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $north->id, 'affiliation_date' => '2026-12-01']);
        $foreignBranch = Client::factory()->concessionaire()->create(['dealership_id' => $foreign->id]);
        Client::factory()->create(['dealership_id' => $foreign->id, 'concessionaire_id' => $foreignBranch->id, 'affiliation_date' => '2026-01-31']);
        $this->portfolio = compact('company', 'head', 'admin', 'north', 'south', 'dealer', 'january', 'february', 'unaffiliated');
    }

    #[TestWith(['head'])]
    #[TestWith(['admin'])]
    #[TestWith(['dealer'])]
    public function test_directory_filters_affiliation_range_inclusively_with_role_visibility(string $role): void
    {
        $data = $this->portfolio;
        $route = $role === 'head' ? 'head-office.clients' : 'clients.index';
        $response = $this->actingAs($data[$role])->get(route($route, [
            'dealership_id' => $data['company']->id, 'customer_kind' => 'person',
            'affiliation_from' => '2026-01-01', 'affiliation_to' => '2026-01-31',
        ]))->assertOk()->assertSee('Afiliación desde')->assertSee('Fecha de afiliación');
        $response->assertViewHas('clients', fn ($clients): bool => $clients->total() === 2
            && $clients->getCollection()->sortBy('id')->values()->modelKeys() === $data['january']->sortBy('id')->values()->modelKeys());
    }

    #[TestWith(['head'])]
    #[TestWith(['admin'])]
    #[TestWith(['dealer'])]
    public function test_dashboards_count_people_by_affiliation_month_and_scope(string $role): void
    {
        $data = $this->portfolio;
        foreach ([$role === 'head' ? 'head-office.overview' : 'workspace.customers', $role === 'head' ? 'head-office.sales.overview' : 'workspace.sales'] as $route) {
            $response = $this->actingAs($data[$role])->get(route($route, [
                'dealership_id' => $data['company']->id, 'affiliation_year' => 2026,
            ]))->assertOk()->assertSee($role === 'dealer' ? 'Clientes afiliados por mes' : 'Clientes y concesionarios por mes');
            $response->assertViewHas('affiliations', fn (array $summary): bool => $summary['year'] === 2026
                && $summary['total'] === ($role === 'dealer' ? 2 : 3) && $summary['months']->count() === 12
                && $summary['months'][0]['count'] === 2 && $summary['months'][1]['count'] === ($role === 'dealer' ? 0 : 1)
                && $summary['months'][11]['count'] === 0);
        }
    }

    public function test_concessionaire_selection_and_year_apply_to_both_summaries(): void
    {
        $data = $this->portfolio;
        foreach (['head-office.overview', 'head-office.sales.overview'] as $route) {
            $this->actingAs($data['head'])->get(route($route, ['concessionaire_id' => $data['north']->id, 'affiliation_year' => 2025]))
                ->assertOk()->assertViewHas('affiliations', fn (array $summary): bool => $summary['total'] === 1
                    && $summary['months'][3]['count'] === 1 && $summary['year'] === 2025);
        }
    }

    public function test_filter_allows_one_date_and_never_includes_unaffiliated_clients(): void
    {
        $data = $this->portfolio;
        $this->actingAs($data['dealer'])->get(route('clients.index', ['affiliation_to' => '2026-01-31']))
            ->assertOk()->assertViewHas('clients', fn ($clients): bool => $clients->total() === 3
                && ! $clients->contains('id', $data['unaffiliated']->id));
        $this->get(route('clients.index', ['affiliation_from' => '2026-01-31', 'affiliation_to' => '2026-01-31']))
            ->assertOk()->assertViewHas('clients', fn ($clients): bool => $clients->total() === 2);
    }

    public function test_invalid_ranges_and_years_are_rejected(): void
    {
        $data = $this->portfolio;
        foreach (['clients.index' => 'dealer', 'head-office.clients' => 'head'] as $route => $role) {
            $this->actingAs($data[$role])->getJson(route($route, ['affiliation_from' => '2026-02-01', 'affiliation_to' => '2026-01-31']))
                ->assertUnprocessable()->assertJsonValidationErrors('affiliation_to');
            $this->getJson(route($route, ['affiliation_from' => 'incorrecta']))->assertUnprocessable()->assertJsonValidationErrors('affiliation_from');
        }
        foreach ([1899, 2027, 'incorrecto'] as $year) {
            $this->actingAs($data['head'])->getJson(route('head-office.sales.overview', ['affiliation_year' => $year]))
                ->assertUnprocessable()->assertJsonValidationErrors('affiliation_year');
        }
    }
}
