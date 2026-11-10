<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClientConcessionaireTest extends TestCase
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

    public function test_admin_sees_all_contact_concessionaires_and_saves_in_the_selected_company(): void
    {
        $first = Dealership::factory()->create();
        $second = Dealership::factory()->create();
        $ownerA = $this->actor('user', $first);
        $ownerB = $this->actor('user', $second);
        $local = Client::factory()->concessionaire()->for($ownerA, 'owner')->create();
        $foreign = Client::factory()->concessionaire()->for($ownerB, 'owner')->create();
        $person = Client::factory()->for($ownerA, 'owner')->create();
        $inactive = Client::factory()->concessionaire()->for($ownerA, 'owner')->create(['active' => false]);

        $this->actingAs($this->actor())->withSession(['dealership_id' => $first->id])
            ->get(route('clients.create'))->assertOk()->assertSee('name="concessionaire_id"', false)
            ->assertViewHas('concessionaires', fn ($contacts) => $contacts->pluck('id')->all() === collect([$local, $foreign])->sortBy('name')->pluck('id')->values()->all())
            ->assertDontSee($person->name)->assertDontSee($inactive->name);

        $this->post(route('clients.store'), ['name' => 'Cliente relacionado', 'dealership_id' => $first->id, 'concessionaire_id' => $foreign->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $client = Client::where('name', 'Cliente relacionado')->firstOrFail();
        $this->assertSame($foreign->id, $client->concessionaire_id);
        $this->assertSame($second->id, $client->dealership_id);
        $this->assertSame($ownerB->id, $client->user_id);
        $this->get(route('clients.show', $client))->assertOk()->assertSee($foreign->name);
    }

    public function test_editing_and_duplicating_preserve_the_selected_contact_concessionaire(): void
    {
        $company = Dealership::factory()->create();
        $owner = $this->actor('user', $company);
        $concessionaire = Client::factory()->concessionaire()->for($owner, 'owner')->create();
        $client = Client::factory()->for($owner, 'owner')->create(['concessionaire_id' => $concessionaire->id]);

        $this->actingAs($this->actor())->get(route('clients.edit', $client))->assertOk()
            ->assertViewHas('selectedConcessionaireId', $concessionaire->id);
        $this->put(route('clients.update', $client), ['name' => 'Cliente editado'])->assertSessionHasNoErrors();
        $this->assertSame($concessionaire->id, $client->fresh()->concessionaire_id);
        $this->get(route('records.duplicate', ['type' => 'client', 'id' => $client->id]))
            ->assertRedirect(route('clients.create'))->assertSessionHasInput('concessionaire_id', $concessionaire->id);
    }

    public function test_assigned_user_gets_the_contact_automatically_and_cannot_override_it(): void
    {
        $company = Dealership::factory()->create();
        $owner = $this->actor('user', $company);
        $concessionaire = Client::factory()->concessionaire()->for($owner, 'owner')->create();
        $other = Client::factory()->concessionaire()->for($owner, 'owner')->create();
        $actor = $this->actor('user', $company);
        $actor->update(['concessionaire_id' => $concessionaire->id]);

        $this->actingAs($actor)->get(route('clients.create'))->assertOk()->assertSee($concessionaire->name)
            ->assertSee('Asignado automáticamente según tu usuario.')->assertDontSee('<select name="concessionaire_id"', false);
        $this->post(route('clients.store'), ['name' => 'Asignación automática'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', ['name' => 'Asignación automática', 'concessionaire_id' => $concessionaire->id, 'user_id' => $actor->id]);
        $this->post(route('clients.store'), ['name' => 'Cambio no permitido', 'concessionaire_id' => $other->id])->assertSessionHasErrors('concessionaire_id');
        $this->assertDatabaseMissing('clients', ['name' => 'Cambio no permitido']);
    }

    public function test_non_admin_cannot_select_a_contact_from_another_company(): void
    {
        $actor = $this->actor('dealership_admin', Dealership::factory()->create());
        $foreign = Client::factory()->concessionaire()->for($this->actor('user', Dealership::factory()->create()), 'owner')->create();

        $this->actingAs($actor)->get(route('clients.create'))->assertOk()->assertDontSee($foreign->name);
        $this->post(route('clients.store'), ['name' => 'Cliente ajeno', 'concessionaire_id' => $foreign->id])->assertSessionHasErrors('concessionaire_id');
        $this->assertDatabaseMissing('clients', ['name' => 'Cliente ajeno']);
    }

    public function test_person_contacts_and_inactive_concessionaires_cannot_be_selected_for_new_clients(): void
    {
        $owner = $this->actor('user', Dealership::factory()->create());
        $person = Client::factory()->for($owner, 'owner')->create();
        $inactive = Client::factory()->concessionaire()->for($owner, 'owner')->create(['active' => false]);

        $this->actingAs($this->actor());
        foreach ([$person, $inactive] as $contact) {
            $this->post(route('clients.store'), ['name' => 'Relación rechazada', 'concessionaire_id' => $contact->id])->assertSessionHasErrors('concessionaire_id');
        }
        $this->assertDatabaseMissing('clients', ['name' => 'Relación rechazada']);
    }

    public function test_existing_client_cannot_be_linked_to_a_different_company(): void
    {
        $owner = $this->actor('user', Dealership::factory()->create());
        $client = Client::factory()->for($owner, 'owner')->create();
        $foreign = Client::factory()->concessionaire()->for($this->actor('user', Dealership::factory()->create()), 'owner')->create();

        $this->actingAs($this->actor())->put(route('clients.update', $client), ['name' => $client->name, 'concessionaire_id' => $foreign->id])
            ->assertSessionHasErrors('concessionaire_id');
        $this->assertNull($client->fresh()->concessionaire_id);
        $this->assertSame($owner->dealership_id, $client->fresh()->dealership_id);
    }

    public function test_existing_inactive_relationship_is_preserved_when_editing_other_fields(): void
    {
        $owner = $this->actor('user', Dealership::factory()->create());
        $contact = Client::factory()->concessionaire()->for($owner, 'owner')->create(['active' => false]);
        $client = Client::factory()->for($owner, 'owner')->create(['concessionaire_id' => $contact->id]);

        $this->actingAs($this->actor())->get(route('clients.edit', $client))->assertOk()->assertSee($contact->name);
        $this->put(route('clients.update', $client), ['name' => 'Datos actualizados'])->assertSessionHasNoErrors();
        $this->assertSame($contact->id, $client->fresh()->concessionaire_id);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_concessionaires_with_clients_or_users_cannot_be_deleted_or_changed_to_people(bool $hasUser): void
    {
        $owner = $this->actor('user', Dealership::factory()->create());
        $contact = Client::factory()->concessionaire()->for($owner, 'owner')->create();
        if ($hasUser) {
            $owner->update(['concessionaire_id' => $contact->id]);
        } else {
            Client::factory()->for($owner, 'owner')->create(['concessionaire_id' => $contact->id]);
        }

        $this->actingAs($this->actor())->delete(route('records.destroy', ['type' => 'client', 'id' => $contact->id]))->assertSessionHasErrors('record');
        $this->put(route('clients.update', $contact), ['name' => $contact->name, 'customer_kind' => 'person'])->assertSessionHasErrors('customer_kind');
        $this->assertSame('dealership', $contact->fresh()->customer_kind);
    }

    public function test_admin_can_assign_a_contact_to_a_user_only_within_the_users_company(): void
    {
        $company = Dealership::factory()->create();
        $actor = $this->actor('dealership_admin', $company);
        $contact = Client::factory()->concessionaire()->for($actor, 'owner')->create();
        $foreign = Client::factory()->concessionaire()->for($this->actor('user', Dealership::factory()->create()), 'owner')->create();
        $values = ['name' => $actor->name, 'username' => 'linked_dealer', 'email' => $actor->email, 'role_id' => $actor->role_id, 'dealership_id' => $company->id, 'active' => 1];

        $this->actingAs($this->actor())->put(route('users.update', $actor), $values + ['concessionaire_id' => $contact->id])->assertSessionHasNoErrors();
        $this->assertSame($contact->id, $actor->fresh()->concessionaire_id);
        $this->get(route('users.edit', $actor))->assertOk()->assertSee('Concesionario de contacto');
        $this->put(route('users.update', $actor), $values + ['concessionaire_id' => $foreign->id])->assertSessionHasErrors('concessionaire_id');
        $this->assertSame($contact->id, $actor->fresh()->concessionaire_id);
    }
}
