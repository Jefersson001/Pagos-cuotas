<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserAutomaticContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]));
    }

    /** @return array<string, int|string> */
    private function accountData(string $role): array
    {
        return ['name' => 'Contacto desde usuario', 'username' => 'automatic_contact', 'email' => 'automatic@example.test', 'password' => 'Automatic-password-123', 'role_id' => Role::where('name', $role)->value('id'), 'active' => 1, 'relate_contact' => 0];
    }

    public function test_user_password_accepts_eight_characters_and_rejects_seven(): void
    {
        $data = array_replace($this->accountData('dealership_admin'), ['password' => 'Clave12']);
        $this->get(route('users.create'))->assertOk()->assertSee('minlength="8"', false)->assertSee('Mínimo 8 caracteres');
        $this->post(route('users.store'), $data)->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['username' => $data['username']]);
        $data['password'] = 'Clave123';
        $this->post(route('users.store'), $data)->assertSessionHasNoErrors();
        $user = User::where('username', $data['username'])->firstOrFail();
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->put(route('users.update', $user), array_replace($data, ['password' => 'Nueva12']))->assertSessionHasErrors('password');
        $this->put(route('users.update', $user), array_replace($data, ['password' => 'Nueva123']))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Nueva123', $user->fresh()->password));
    }

    public function test_new_dealer_creates_and_links_its_own_contact_with_name_and_email(): void
    {
        $data = $this->accountData('dealership_admin');
        $this->post(route('users.store'), $data)->assertSessionHasNoErrors();
        $user = User::where('username', $data['username'])->firstOrFail();
        $contact = $user->concessionaire;
        $this->assertSame('dealership', $contact->customer_kind);
        $this->assertSame($data['name'], $contact->name);
        $this->assertSame($data['email'], $contact->email);
        $this->assertSame($user->id, $contact->user_id);
        $this->assertSame($user->dealership_id, $contact->dealership_id);
        $this->assertNull($contact->concessionaire_id);
        $this->assertNull($user->client_id);
        $this->assertSame('', $contact->document);
        $this->assertSame('', $contact->phone);
        $this->assertTrue($user->hasActiveAccess());
        $this->assertDatabaseCount('clients', 1);
        $data['name'] = 'Usuario actualizado';
        $this->put(route('users.update', $user), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('clients', 1);
        $this->assertSame($contact->id, $user->fresh()->concessionaire_id);
    }

    public function test_new_customer_creates_a_contact_related_to_the_selected_dealer(): void
    {
        $company = Dealership::factory()->create();
        $dealer = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $data = $this->accountData('customer') + ['concessionaire_id' => $dealer->id];
        $this->post(route('users.store'), $data)->assertSessionHasNoErrors();
        $user = User::where('username', $data['username'])->firstOrFail();
        $this->assertSame($company->id, $user->dealership_id);
        $this->assertSame($dealer->id, $user->concessionaire_id);
        $this->assertSame('person', $user->client->customer_kind);
        $this->assertSame($dealer->id, $user->client->concessionaire_id);
        $this->assertSame($data['email'], $user->client->email);
        $this->assertSame($data['name'], $user->client->name);
        $this->assertTrue($user->hasActiveAccess());
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $data['email'], 'password' => $data['password']])->assertRedirect(route('home'));
        $this->get(route('portal.account'))->assertOk();
    }

    #[TestWith(['dealership_admin'])]
    #[TestWith(['customer'])]
    public function test_checked_mode_links_an_existing_contact_without_creating_a_duplicate(string $role): void
    {
        $dealer = Client::factory()->concessionaire()->create();
        $person = Client::factory()->create(['dealership_id' => $dealer->dealership_id, 'concessionaire_id' => $dealer->id]);
        $data = array_replace($this->accountData($role), ['relate_contact' => 1, 'concessionaire_id' => $dealer->id]);
        if ($role === 'customer') {
            $data['client_id'] = $person->id;
        }
        $this->post(route('users.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('clients', 2);
        $user = User::where('username', $data['username'])->firstOrFail();
        $this->assertSame($dealer->id, $user->concessionaire_id);
        $this->assertSame($role === 'customer' ? $person->id : null, $user->client_id);
    }

    public function test_new_customer_rejects_missing_inactive_wrong_type_or_foreign_dealer_without_creating_records(): void
    {
        $inactive = Client::factory()->concessionaire()->create(['active' => false]);
        $person = Client::factory()->create();
        $dealer = Client::factory()->concessionaire()->create();
        foreach ([null, $inactive->id, $person->id] as $id) {
            $this->post(route('users.store'), $this->accountData('customer') + ['concessionaire_id' => $id])->assertSessionHasErrors('concessionaire_id');
        }
        $this->post(route('users.store'), $this->accountData('customer') + ['concessionaire_id' => $dealer->id, 'dealership_id' => Dealership::factory()->create()->id])->assertSessionHasErrors('concessionaire_id');
        $this->assertDatabaseMissing('users', ['username' => 'automatic_contact']);
        $this->assertDatabaseCount('clients', 3);
    }

    public function test_invalid_user_and_automatic_mode_with_an_existing_selection_do_not_create_contacts(): void
    {
        $dealer = Client::factory()->concessionaire()->create();
        $this->post(route('users.store'), array_replace($this->accountData('dealership_admin'), ['password' => 'short']))->assertSessionHasErrors('password');
        $this->post(route('users.store'), $this->accountData('dealership_admin') + ['concessionaire_id' => $dealer->id])->assertSessionHasErrors('relate_contact');
        $this->assertDatabaseMissing('users', ['username' => 'automatic_contact']);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_contact_failure_rolls_back_the_new_user_too(): void
    {
        Client::creating(function (Client $contact): void {
            throw ValidationException::withMessages(['contact' => 'No se pudo guardar el contacto.']);
        });
        $this->post(route('users.store'), $this->accountData('dealership_admin'))->assertSessionHasErrors('contact');
        $this->assertDatabaseMissing('users', ['username' => 'automatic_contact']);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_automatic_contact_creation_warns_when_the_email_is_already_registered(): void
    {
        $values = $this->accountData('dealership_admin');
        $contact = Client::factory()->concessionaire()->create(['email' => $values['email']]);
        $this->post(route('users.store'), $values + ['dealership_id' => $contact->dealership_id])->assertSessionHasErrors('relate_contact');
        $this->assertDatabaseMissing('users', ['username' => $values['username']]);
        $this->assertDatabaseCount('clients', 1);
    }
}
