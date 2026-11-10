<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actor(string $role = 'admin', ?Dealership $company = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company?->id]);
    }

    public function test_list_combines_both_types_and_supports_type_search_and_state_filters(): void
    {
        $owner = $this->actor('user', Dealership::factory()->create());
        $person = Client::factory()->for($owner, 'owner')->create(['name' => 'Cliente de Caracas']);
        $dealer = Client::factory()->concessionaire()->for($owner, 'owner')->create(['name' => 'Concesionario de Caracas']);
        Client::factory()->concessionaire()->for($owner, 'owner')->create(['active' => false]);

        $this->actingAs($this->actor())->get(route('clients.index'))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->total() === 3)->assertSee($person->name)->assertSee($dealer->name);
        $this->get(route('clients.index', ['customer_kind' => 'dealership', 'active' => 1, 'q' => 'Caracas']))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->total() === 1 && $clients->first()->is($dealer));
        $this->get(route('clients.index', ['customer_kind' => 'person']))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->total() === 1 && $clients->first()->is($person));
        $this->get(route('clients.index', ['customer_kind' => 'otro']))->assertSessionHasErrors('customer_kind');
    }

    public function test_single_form_selects_type_and_keeps_it_after_validation_errors(): void
    {
        $this->actingAs($this->actor())->get(route('clients.create'))->assertOk()->assertSee('<select name="customer_kind"', false)
            ->assertViewHas('customerKind', 'person');
        $this->get(route('clients.create', ['customer_kind' => 'dealership']))->assertOk()->assertViewHas('customerKind', 'dealership');
        $this->from(route('clients.create'))->post(route('clients.store'), ['customer_kind' => 'dealership', 'name' => ''])
            ->assertSessionHasErrors('name')->assertSessionHasInput('customer_kind', 'dealership');
        $this->get(route('clients.create'))->assertOk()->assertViewHas('customerKind', 'dealership');
    }

    public function test_legacy_concessionaire_links_open_the_unified_list_and_form(): void
    {
        $this->actingAs($this->actor())->get(route('concessionaires.index', ['q' => 'Caracas']))
            ->assertRedirect(route('clients.index', ['q' => 'Caracas', 'customer_kind' => 'dealership']));
        $this->get(route('concessionaires.create'))->assertRedirect(route('clients.create', ['customer_kind' => 'dealership']));
    }

    public function test_creating_both_types_from_one_form_preserves_their_relationship(): void
    {
        $this->actingAs($this->actor())->post(route('clients.store'), ['customer_kind' => 'dealership', 'name' => 'Concesionario unificado', 'business_type' => 'TALLER'])
            ->assertSessionHasNoErrors();
        $dealer = Client::where('name', 'Concesionario unificado')->firstOrFail();
        $this->post(route('clients.store'), ['customer_kind' => 'person', 'name' => 'Cliente relacionado', 'concessionaire_id' => $dealer->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', ['name' => 'Cliente relacionado', 'customer_kind' => 'person', 'concessionaire_id' => $dealer->id]);
        $this->get(route('clients.index'))->assertOk()->assertSee('Concesionario unificado')->assertSee('Cliente relacionado');
        $this->get(route('clients.edit', $dealer))->assertOk()->assertViewHas('customerKind', 'dealership');
        $this->get(route('records.duplicate', ['type' => 'client', 'id' => $dealer->id]))->assertRedirect(route('clients.create'))
            ->assertSessionHasInput('customer_kind', 'dealership');
        $this->get(route('clients.create'))->assertOk()->assertViewHas('customerKind', 'dealership');
    }

    public function test_type_changes_cannot_break_an_existing_customer_portal_account(): void
    {
        $company = Dealership::factory()->create();
        $owner = $this->actor('user', $company);
        $client = Client::factory()->for($owner, 'owner')->create();
        $account = $this->actor('customer', $company);
        $account->update(['client_id' => $client->id]);

        $this->actingAs($this->actor())->put(route('clients.update', $client), ['name' => $client->name, 'customer_kind' => 'dealership'])
            ->assertSessionHasErrors('customer_kind');
        $this->assertSame('person', $client->fresh()->customer_kind);
        $this->assertSame($client->id, $account->fresh()->client_id);
    }

    public function test_unified_list_keeps_ownership_and_company_access_restrictions(): void
    {
        $company = Dealership::factory()->create();
        $owner = $this->actor('user', $company);
        Client::factory()->for($owner, 'owner')->create(['name' => 'Cliente propio']);
        Client::factory()->concessionaire()->for($owner, 'owner')->create(['name' => 'Concesionario propio']);
        $colleague = Client::factory()->for($this->actor('user', $company), 'owner')->create(['name' => 'Cliente del compañero']);
        $foreign = Client::factory()->concessionaire()->for($this->actor('user', Dealership::factory()->create()), 'owner')->create(['name' => 'Concesionario reservado']);

        $this->actingAs($owner)->get(route('clients.index'))->assertOk()->assertSee('Cliente propio')->assertSee('Concesionario propio')
            ->assertDontSee($colleague->name)->assertDontSee($foreign->name);
        $this->get(route('clients.index', ['customer_kind' => 'dealership']))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->total() === 1)->assertDontSee($foreign->name);
    }
}
