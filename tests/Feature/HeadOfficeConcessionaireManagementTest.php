<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeadOfficeConcessionaireManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actor(string $role, Dealership $company): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company->id]);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $foreignCompany = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = $this->actor('head_office', $company);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $dealer = Client::factory()->concessionaire()->for($admin, 'owner')->create(['dealership_id' => $company->id]);
        $foreign = Client::factory()->concessionaire()->create(['dealership_id' => $foreignCompany->id]);
        $customer = Client::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $dealer->id]);
        $this->actingAs($head);

        return compact('company', 'foreignCompany', 'head', 'dealer', 'foreign', 'customer');
    }

    /** @return array<string, int|string> */
    private function account(): array
    {
        return ['name' => 'Concesionario de la matriz', 'username' => 'dealer_'.Str::lower(Str::random(8)), 'email' => Str::lower(Str::random(8)).'@example.test', 'password' => 'Head-office-password-123', 'role_id' => Role::where('name', 'dealership_admin')->value('id'), 'active' => 1];
    }

    public function test_head_office_can_create_and_complete_only_concessionaire_contacts_in_its_organization(): void
    {
        $data = $this->data();
        $this->get(route('clients.create', ['customer_kind' => 'person']))->assertOk()->assertViewHas('customerKind', 'dealership')->assertDontSee('<option value="person">', false);
        $this->post(route('clients.store'), ['name' => 'Nuevo concesionario', 'email' => 'new-dealer@example.test'])->assertSessionHasNoErrors();
        $contact = Client::where('name', 'Nuevo concesionario')->firstOrFail();
        $this->assertSame('dealership', $contact->customer_kind);
        $this->assertSame($data['company']->id, $contact->dealership_id);
        $this->assertSame($data['head']->id, $contact->user_id);
        $this->get(route('clients.edit', $contact))->assertOk();
        $this->put(route('clients.update', $contact), ['name' => 'Ficha completada', 'phone' => '04121234567'])->assertSessionHasNoErrors();
        $this->assertSame('04121234567', $contact->fresh()->phone);
        $this->put(route('clients.update', $data['dealer']), ['name' => 'Concesionario actualizado'])->assertSessionHasNoErrors();
    }

    public function test_head_office_can_create_a_dealer_user_with_new_or_existing_contact_and_edit_its_access(): void
    {
        $data = $this->data();
        $this->get(route('users.create'))->assertOk()->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->all() === ['dealership_admin'])->assertViewHas('accountClients', fn ($clients) => $clients->isEmpty())->assertViewHas('accountConcessionaires', fn ($contacts) => $contacts->contains('id', $data['dealer']->id) && ! $contacts->contains('id', $data['foreign']->id));
        $newAccount = $this->account() + ['relate_contact' => 0];
        $this->post(route('users.store'), $newAccount)->assertSessionHasNoErrors();
        $user = User::where('username', $newAccount['username'])->firstOrFail();
        $this->assertSame($data['company']->id, $user->dealership_id);
        $this->assertSame($data['company']->id, $user->concessionaire->dealership_id);
        $this->assertSame('dealership', $user->concessionaire->customer_kind);
        $this->assertTrue($user->hasActiveAccess());
        $existingAccount = $this->account() + ['relate_contact' => 1, 'concessionaire_id' => $data['dealer']->id];
        $count = Client::count();
        $this->post(route('users.store'), $existingAccount)->assertSessionHasNoErrors();
        $linked = User::where('username', $existingAccount['username'])->firstOrFail();
        $this->assertSame($data['dealer']->id, $linked->concessionaire_id);
        $this->assertDatabaseCount('clients', $count);
        $existingAccount['name'] = 'Acceso actualizado';
        $this->put(route('users.update', $linked), $existingAccount)->assertSessionHasNoErrors();
        $this->get(route('users.show', $linked))->assertOk()->assertSee('Acceso actualizado')->assertDontSee('Eliminar registro')->assertDontSee('Duplicar');
    }

    public function test_head_office_cannot_create_retail_contacts_or_users_or_escalate_roles(): void
    {
        $data = $this->data();
        $this->post(route('clients.store'), ['name' => 'Cliente rechazado', 'customer_kind' => 'person', 'concessionaire_id' => $data['dealer']->id])->assertSessionHasErrors('customer_kind');
        $this->put(route('clients.update', $data['dealer']), ['name' => 'Cambio rechazado', 'customer_kind' => 'person'])->assertSessionHasErrors('customer_kind');
        $this->get(route('clients.edit', $data['customer']))->assertForbidden();
        foreach (['customer', 'admin', 'head_office', 'user'] as $role) {
            $values = array_replace($this->account(), ['role_id' => Role::where('name', $role)->value('id')]);
            $this->post(route('users.store'), $values)->assertForbidden();
        }
        $this->assertDatabaseMissing('clients', ['name' => 'Cliente rechazado']);
        $this->assertDatabaseMissing('users', ['name' => 'Concesionario de la matriz']);
        $this->post(route('orders.store'), [])->assertForbidden();
        $this->post(route('receipts.store'), [])->assertForbidden();
    }

    public function test_head_office_cannot_select_foreign_contacts_companies_or_user_records(): void
    {
        $data = $this->data();
        $this->post(route('clients.store'), ['name' => 'Contacto ajeno', 'customer_kind' => 'dealership', 'dealership_id' => $data['foreignCompany']->id])->assertForbidden();
        $this->post(route('users.store'), $this->account() + ['relate_contact' => 0, 'dealership_id' => $data['foreignCompany']->id])->assertForbidden();
        $this->post(route('users.store'), $this->account() + ['relate_contact' => 1, 'concessionaire_id' => $data['foreign']->id])->assertSessionHasErrors('concessionaire_id');
        foreach ([$this->actor('dealership_admin', $data['foreignCompany']), $this->actor('customer', $data['company']), $this->actor('admin', $data['company'])] as $other) {
            $this->get(route('users.show', $other))->assertForbidden();
            $this->get(route('users.edit', $other))->assertForbidden();
            $this->put(route('users.update', $other), $this->account())->assertForbidden();
        }
        $this->get(route('users'))->assertOk()->assertViewHas('users', fn ($users) => $users->every(fn ($row) => $row->dealership_id === $data['company']->id && $row->managesDealership()));
        $this->assertDatabaseMissing('clients', ['name' => 'Contacto ajeno']);
    }
}
