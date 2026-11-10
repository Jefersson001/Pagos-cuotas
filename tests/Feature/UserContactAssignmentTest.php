<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserContactAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
    }

    /** @return array<string, int|string> */
    private function accountData(): array
    {
        return ['name' => 'Cuenta del cliente', 'username' => 'contact_customer', 'email' => 'contact-customer@example.test',
            'password' => 'Contact-password-12345', 'role_id' => Role::where('name', 'customer')->value('id'), 'active' => 1];
    }

    public function test_admin_form_loads_contact_concessionaires_and_people_outside_the_current_company_filter(): void
    {
        $admin = $this->admin();
        $company = Dealership::factory()->create();
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create(['dealership_id' => $company->id]);
        $client = Client::factory()->for($admin, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $contact->id]);

        $this->actingAs($admin)->withSession(['dealership_id' => Dealership::firstOrFail()->id])->get(route('users.create'))->assertOk()
            ->assertViewHas('accountConcessionaires', fn ($contacts) => $contacts->contains('id', $contact->id) && ! $contacts->contains('id', $client->id))
            ->assertViewHas('accountClients', fn ($clients) => $clients->contains(fn ($row) => $row->id === $client->id && $row->concessionaire_id === $contact->id)
                && ! $clients->contains('id', $contact->id) && $clients->every(fn ($row) => $row->customer_kind === 'person'));
    }

    public function test_concessionaire_cannot_be_saved_as_the_client_file_when_creating_or_editing_an_account(): void
    {
        $admin = $this->admin();
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create(['name' => 'Concesionario con acceso propio']);
        $retail = Client::factory()->for($admin, 'owner')->create(['concessionaire_id' => $contact->id]);

        $this->actingAs($admin)->post(route('users.store'), $this->accountData() + ['client_id' => $contact->id, 'concessionaire_id' => $contact->id])
            ->assertSessionHasErrors('client_id');
        $this->assertDatabaseMissing('users', ['username' => 'contact_customer']);
        $this->post(route('users.store'), $this->accountData() + ['client_id' => $retail->id, 'concessionaire_id' => $contact->id])->assertSessionHasNoErrors();
        $user = User::where('username', 'contact_customer')->firstOrFail();
        $this->put(route('users.update', $user), $this->accountData() + ['client_id' => $contact->id, 'concessionaire_id' => $contact->id])->assertSessionHasErrors('client_id');
        $this->assertSame($retail->id, $user->fresh()->client_id);
    }

    public function test_customer_account_uses_the_contact_created_in_clients_and_infers_its_company(): void
    {
        $admin = $this->admin();
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create();
        $client = Client::factory()->for($admin, 'owner')->create(['concessionaire_id' => $contact->id, 'name' => 'Ficha permitida']);
        Client::factory()->for($admin, 'owner')->create(['concessionaire_id' => $contact->id, 'name' => 'FICHA DEL VECINO']);
        $values = $this->accountData();

        $this->actingAs($admin)->post(route('users.store'), $values + ['client_id' => $client->id, 'concessionaire_id' => $contact->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', $values['email'])->firstOrFail();
        $this->assertSame($contact->id, $user->concessionaire_id);
        $this->assertSame($client->id, $user->client_id);
        $this->assertSame($contact->dealership_id, $user->dealership_id);
        $this->assertTrue($user->hasActiveAccess());

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $values['email'], 'password' => $values['password']])->assertRedirect(route('home'));
        $this->get(route('portal.account'))->assertOk()->assertSee('Ficha permitida')->assertDontSee('FICHA DEL VECINO');
        $this->assertSame([$client->id], Client::visibleTo($user)->pluck('id')->all());
    }

    public function test_concessionaire_account_selects_a_contact_and_infers_its_company_on_create_and_edit(): void
    {
        $admin = $this->admin();
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create(['dealership_id' => $company->id]);
        $values = array_replace($this->accountData(), ['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'concessionaire_id' => $contact->id]);
        $this->actingAs($admin)->withSession(['dealership_id' => Dealership::firstOrFail()->id])->post(route('users.store'), $values)->assertSessionHasNoErrors();
        $user = User::where('username', $values['username'])->firstOrFail();
        $this->assertSame($company->id, $user->dealership_id);
        $this->assertSame($contact->id, $user->concessionaire_id);
        $this->assertNull($user->client_id);
        $this->assertTrue($user->hasActiveAccess());
        $replacement = Client::factory()->concessionaire()->for($admin, 'owner')->create(['dealership_id' => $company->id]);
        $values['concessionaire_id'] = $replacement->id;
        $this->put(route('users.update', $user), $values)->assertSessionHasNoErrors();
        $this->assertSame($replacement->id, $user->fresh()->concessionaire_id);
    }

    public function test_concessionaire_account_rejects_people_inactive_contacts_and_a_forged_company(): void
    {
        $admin = $this->admin();
        $person = Client::factory()->for($admin, 'owner')->create();
        $inactive = Client::factory()->concessionaire()->for($admin, 'owner')->create(['active' => false]);
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create();
        $values = array_replace($this->accountData(), ['role_id' => Role::where('name', 'dealership_admin')->value('id')]);
        $this->actingAs($admin);
        foreach ([$person, $inactive] as $invalid) {
            $this->post(route('users.store'), $values + ['concessionaire_id' => $invalid->id])->assertSessionHasErrors('concessionaire_id');
        }
        $this->post(route('users.store'), $values + ['concessionaire_id' => $contact->id, 'dealership_id' => Dealership::factory()->create()->id])->assertSessionHasErrors('concessionaire_id');
        $this->assertDatabaseMissing('users', ['username' => $values['username']]);
    }

    public function test_contact_selection_rejects_wrong_types_inactive_contacts_and_unrelated_clients(): void
    {
        $admin = $this->admin();
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create();
        $otherContact = Client::factory()->concessionaire()->for($admin, 'owner')->create();
        $inactive = Client::factory()->concessionaire()->for($admin, 'owner')->create(['active' => false]);
        $client = Client::factory()->for($admin, 'owner')->create(['concessionaire_id' => $contact->id]);
        $values = $this->accountData() + ['client_id' => $client->id];

        $this->actingAs($admin);
        foreach ([$client, $inactive] as $invalidContact) {
            $this->post(route('users.store'), $values + ['concessionaire_id' => $invalidContact->id])->assertSessionHasErrors('concessionaire_id');
        }
        $this->post(route('users.store'), $values + ['concessionaire_id' => $otherContact->id])->assertSessionHasErrors('client_id');
        $this->assertDatabaseMissing('users', ['email' => $values['email']]);
    }

    public function test_forged_company_is_rejected_and_missing_contact_is_inferred_from_the_client(): void
    {
        $admin = $this->admin();
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create();
        $client = Client::factory()->for($admin, 'owner')->create(['concessionaire_id' => $contact->id]);
        $foreignCompany = Dealership::factory()->create();
        $values = $this->accountData() + ['client_id' => $client->id];

        $this->actingAs($admin)->post(route('users.store'), $values + ['dealership_id' => $foreignCompany->id, 'concessionaire_id' => $contact->id])
            ->assertSessionHasErrors('client_id');
        $this->post(route('users.store'), $values)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => $values['email'], 'client_id' => $client->id,
            'concessionaire_id' => $contact->id, 'dealership_id' => $client->dealership_id]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_customer_access_is_revoked_if_the_contact_is_inactive_or_the_client_changes_contact(bool $reassignClient): void
    {
        $admin = $this->admin();
        $contact = Client::factory()->concessionaire()->for($admin, 'owner')->create();
        $client = Client::factory()->for($admin, 'owner')->create(['concessionaire_id' => $contact->id]);
        $account = User::factory()->create(['role_id' => Role::where('name', 'customer')->value('id'), 'dealership_id' => $client->dealership_id,
            'client_id' => $client->id, 'concessionaire_id' => $contact->id]);

        $this->actingAs($account)->get(route('portal.account'))->assertOk();
        if ($reassignClient) {
            $replacement = Client::factory()->concessionaire()->for($admin, 'owner')->create();
            $client->update(['concessionaire_id' => $replacement->id]);
        } else {
            $contact->update(['active' => false]);
        }
        $this->get(route('portal.account'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
