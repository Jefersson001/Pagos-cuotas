<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClientFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role = 'user', ?Dealership $dealer = null, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => Role::where('name', $role)->firstOrFail()->id, 'dealership_id' => $dealer?->id]);
    }

    private function data(): array
    {
        return ['name' => 'Cliente de prueba', 'document' => 'FORM-01', 'phone' => '04121234567', 'active' => 1];
    }

    public function test_responsible_lookup_only_returns_active_users_of_the_selected_dealership(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $admin = $this->user('admin');
        $allowed = $this->user('user', $a, ['name' => 'Asesor A']);
        $this->user('user', $a, ['active' => false]);
        $this->user('user', $b);
        $this->actingAs($admin)->getJson(route('clients.responsible-users', ['dealership_id' => $a->id]))->assertOk()->assertExactJson(['users' => [['id' => $allowed->id, 'name' => 'Asesor A']]]);
        $this->getJson(route('clients.responsible-users', ['dealership_id' => $a->id]))->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_lookup_requires_management_permissions_and_checks_dealership_access(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $manager = $this->user('dealership_admin', $a);
        $advisor = $this->user('user', $a);
        $this->get(route('clients.responsible-users', ['dealership_id' => $a->id]))->assertRedirect(route('login'));
        $this->actingAs($manager)->getJson(route('clients.responsible-users', ['dealership_id' => $a->id]))->assertOk();
        $this->getJson(route('clients.responsible-users', ['dealership_id' => $b->id]))->assertForbidden();
        $this->actingAs($advisor)->getJson(route('clients.responsible-users', ['dealership_id' => $a->id]))->assertForbidden();
    }

    public function test_initial_and_edit_forms_only_load_users_of_the_client_dealership(): void
    {
        $a = Dealership::factory()->create();
        $b = Dealership::factory()->create();
        $admin = $this->user('admin');
        $aUser = $this->user('user', $a);
        $bUser = $this->user('user', $b);
        $client = Client::create($this->data() + ['user_id' => $bUser->id]);
        $this->actingAs($admin)->post(route('dealership-context'), ['dealership_id' => $a->id]);
        $this->get(route('clients.create'))->assertOk()->assertViewHas('users', fn ($users) => $users->pluck('id')->all() === [$aUser->id]);
        $this->get(route('clients.edit', $client))->assertOk()->assertViewHas('users', fn ($users) => $users->pluck('id')->all() === [$bUser->id])->assertViewHas('selectedDealershipId', $b->id);
    }

    public function test_empty_company_returns_no_advisors_and_uses_admin_as_automatic_responsible(): void
    {
        $dealer = Dealership::factory()->create();
        $admin = $this->user('admin');
        $this->actingAs($admin)->getJson(route('clients.responsible-users', ['dealership_id' => $dealer->id]))->assertOk()->assertExactJson(['users' => []]);
        $this->post(route('clients.store'), $this->data() + ['dealership_id' => $dealer->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', ['user_id' => $admin->id, 'dealership_id' => $dealer->id]);
    }

    #[TestWith(['+58 (412) 123-4567', '584121234567'])]
    #[TestWith(['0412 123-4567', '584121234567'])]
    #[TestWith(['00 58 412 123 4567', '584121234567'])]
    #[TestWith(['+1 (305) 555-0123', '13055550123'])]
    #[TestWith(['', null])]
    public function test_whatsapp_accepts_common_formats_and_is_saved_normalized(string $input, ?string $expected): void
    {
        $user = $this->user();
        $this->actingAs($user)->post(route('clients.store'), $this->data() + ['whatsapp' => $input])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('clients', ['document' => 'FORM-01', 'whatsapp' => $expected]);
    }

    #[TestWith(['abc1234567'])]
    #[TestWith(['+58 412'])]
    #[TestWith(['++58 4121234567'])]
    public function test_invalid_whatsapp_is_rejected_without_creating_the_client(string $input): void
    {
        $this->actingAs($this->user())->post(route('clients.store'), $this->data() + ['whatsapp' => $input])->assertSessionHasErrors('whatsapp');
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_edit_preserves_the_responsible_and_accepts_a_formatted_whatsapp(): void
    {
        $user = $this->user();
        $client = Client::create($this->data() + ['user_id' => $user->id]);
        $this->actingAs($user)->put(route('clients.update', $client), $this->data() + ['whatsapp' => '(0414) 765-4321'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'user_id' => $user->id, 'whatsapp' => '584147654321']);
    }
}
