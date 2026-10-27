<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role = 'user', ?Dealership $dealer = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->firstOrFail()->id, 'dealership_id' => $dealer?->id]);
    }

    private function client(User $owner, string $name, string $document, bool $active = true): Client
    {
        return Client::create(['user_id' => $owner->id, 'name' => $name, 'document' => $document, 'phone' => '04121234567', 'active' => $active]);
    }

    public function test_lookup_respects_roles_dealerships_and_active_clients(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $inactive = Dealership::factory()->create(['active' => false]);
        $advisor = $this->user('user', $a);
        $manager = $this->user('dealership_admin', $a);
        $global = $this->user('admin');
        $own = $this->client($advisor, 'A cliente propio', 'OWN-DOC');
        $colleague = $this->client($this->user('user', $a), 'B cliente compañero', 'TEAM-DOC');
        $foreign = $this->client($this->user('user', $b), 'C cliente ajeno', 'FOREIGN-DOC');
        $this->client($advisor, 'D cliente inactivo', 'INACTIVE-DOC', false);
        $this->client($this->user('user', $inactive), 'E sede inactiva', 'DISABLED-DOC');
        $this->actingAs($advisor)->getJson(route('orders.client-options'))->assertOk()->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $own->id);
        $this->getJson(route('orders.client-options', ['q' => 'FOREIGN-DOC', 'dealership_id' => $b->id]))->assertOk()->assertJsonCount(0, 'clients');
        $this->actingAs($manager)->getJson(route('orders.client-options'))->assertOk()->assertJsonCount(2, 'clients')->assertJsonPath('clients.1.id', $colleague->id);
        $this->actingAs($global)->getJson(route('orders.client-options'))->assertOk()->assertJsonCount(3, 'clients')->assertJsonPath('clients.2.id', $foreign->id);
    }

    public function test_search_matches_name_or_document_and_returns_limited_results(): void
    {
        $owner = $this->user();
        $expected = null;
        for ($n = 1; $n <= 21; $n++) {
            $client = $this->client($owner, 'Cliente '.str_pad($n, 2, '0', STR_PAD_LEFT), 'DOC-'.$n);
            if ($n === 20) {
                $expected = $client;
            }
        }
        $this->actingAs($owner)->getJson(route('orders.client-options'))->assertOk()->assertJsonCount(15, 'clients')->assertJsonPath('has_more', true);
        $this->getJson(route('orders.client-options', ['q' => 'DOC-20']))->assertOk()->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $expected->id)->assertJsonPath('has_more', false);
        $this->getJson(route('orders.client-options', ['q' => 'Cliente 20']))->assertOk()->assertJsonCount(1, 'clients');
        $this->getJson(route('orders.client-options', ['q' => "' OR 1=1 --"]))->assertOk()->assertJsonCount(0, 'clients');
        $this->getJson(route('orders.client-options', ['q' => str_repeat('a', 121)]))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_prefill_only_includes_an_authorized_active_client(): void
    {
        $dealer = Dealership::factory()->create();
        $advisor = $this->user('user', $dealer);
        $own = $this->client($advisor, 'Cliente propio', 'OWN');
        $other = $this->client($this->user('user', $dealer), 'Cliente ajeno', 'OTHER');
        $this->actingAs($advisor)->get(route('orders.create', ['client_id' => $own->id]))->assertOk()->assertViewHas('selectedClient', fn ($client) => $client['id'] === $own->id);
        $this->get(route('orders.create', ['client_id' => $other->id]))->assertOk()->assertViewHas('selectedClient', null)->assertDontSee('Cliente ajeno');
        $this->get(route('orders.create'))->assertOk()->assertViewHas('selectedClient', null);
    }

    public function test_lookup_requires_login_and_keeps_an_explicit_global_dealership_scope(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $global = $this->user('admin');
        $this->client($this->user('user', $a), 'Cliente A', 'A');
        $other = $this->client($this->user('user', $b), 'Cliente B', 'B');
        $this->getJson(route('orders.client-options'))->assertUnauthorized();
        $this->actingAs($global)->post(route('dealership-context'),['dealership_id' => $a->id]);
        $this->getJson(route('orders.client-options',['dealership_id' => $b->id]))->assertOk()->assertJsonCount(1,'clients')->assertJsonPath('clients.0.id',$other->id);
    }
}
