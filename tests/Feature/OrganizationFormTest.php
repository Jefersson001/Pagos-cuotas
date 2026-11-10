<?php

namespace Tests\Feature;

use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]));
    }

    public function test_form_creates_a_head_office_without_asking_for_type_or_parent(): void
    {
        $this->get(route('dealerships.create'))->assertOk()->assertDontSee('name="organization_type"', false)->assertDontSee('name="parent_id"', false)->assertDontSee('Tipo de organización')->assertDontSee('Selecciona la casa matriz');
        $this->post(route('dealerships.store'), ['name' => 'Empresa Central', 'code' => 'CENTRAL', 'active' => 1])->assertSessionHasNoErrors();
        $company = Dealership::where('code', 'CENTRAL')->firstOrFail();
        $this->assertSame('head_office', $company->organization_type);
        $this->assertNull($company->parent_id);
        $this->get(route('users.create'))->assertOk()->assertViewHas('companies', fn ($companies) => $companies->contains('id', $company->id));
    }

    public function test_editing_without_hidden_hierarchy_fields_preserves_existing_relationships(): void
    {
        $headOffice = Dealership::factory()->create(['organization_type' => 'head_office']);
        $branch = Dealership::factory()->create(['organization_type' => 'concessionaire', 'parent_id' => $headOffice->id]);
        $legacyCompany = Dealership::factory()->create(['organization_type' => 'concessionaire', 'parent_id' => null]);
        foreach ([$headOffice, $branch, $legacyCompany] as $company) {
            $this->get(route('dealerships.edit', $company))->assertOk()->assertDontSee('name="organization_type"', false)->assertDontSee('name="parent_id"', false);
            $this->put(route('dealerships.update', $company), ['name' => 'Nombre actualizado', 'code' => $company->code, 'active' => 1])->assertSessionHasNoErrors();
            $saved = $company->fresh();
            $this->assertSame($company->organization_type, $saved->organization_type);
            $this->assertSame($company->parent_id, $saved->parent_id);
        }
    }

    public function test_legacy_root_organization_is_available_for_a_head_office_user_after_normalization(): void
    {
        $company = Dealership::factory()->create(['name' => 'Bera Cars', 'organization_type' => 'concessionaire', 'parent_id' => null]);
        $branch = Dealership::factory()->create(['organization_type' => 'concessionaire', 'parent_id' => $company->id]);
        $migration = require database_path('migrations/2026_11_09_130218_normalize_root_organizations_as_head_offices.php');
        $migration->up();
        $migration->up();
        $this->assertSame('head_office', $company->fresh()->organization_type);
        $this->assertSame('concessionaire', $branch->fresh()->organization_type);
        $this->assertSame($company->id, $branch->fresh()->parent_id);
        $this->get(route('users.create'))->assertOk()->assertViewHas('companies', fn ($companies) => $companies->contains(fn ($row) => $row->id === $company->id && $row->organization_type === 'head_office'));
        $this->post(route('users.store'), ['name' => 'Usuario de Bera Cars', 'username' => 'bera_head_office', 'email' => 'head-office@example.test', 'password' => 'Head-office-password-123', 'role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id, 'active' => 1])->assertSessionHasNoErrors();
        $user = User::where('username', 'bera_head_office')->firstOrFail();
        $this->assertSame($company->id, $user->dealership_id);
        $this->assertTrue($user->hasActiveAccess());
    }
}
