<?php

namespace Tests\Feature;

use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role = 'admin', ?int $company = null, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company]);
    }

    public function test_settings_counts_users_methods_and_activity_with_correct_date_boundaries(): void
    {
        $company = Dealership::factory()->create();
        $owner = $this->user('dealership_admin', $company->id, ['name' => '<script>alert("usuario")</script>', 'last_login_at' => now()]);
        $this->user('user', $company->id, ['last_login_at' => '2026-10-23 00:00:00']);
        $this->user('user', $company->id, ['active' => false, 'last_login_at' => now()]);
        $this->user('user', $company->id, ['last_login_at' => '2026-10-22 23:59:59', 'created_at' => '2026-09-30 23:59:59']);
        $this->user('user', $company->id);
        $used = PaymentMethod::create(['dealership_id' => $company->id, 'name' => '<script>alert("metodo")</script>', 'active' => true]);
        $inactive = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Anterior', 'active' => false]);
        PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Sin uso', 'active' => true]);
        Receipt::factory()->create(['user_id' => $owner->id, 'payment_method_id' => $used->id, 'created_at' => '2026-10-01 00:00:00']);
        Receipt::factory()->create(['user_id' => $owner->id, 'payment_method_id' => $used->id]);
        Receipt::factory()->create(['user_id' => $owner->id, 'payment_method_id' => $inactive->id, 'created_at' => '2026-09-30 23:59:59']);
        Receipt::factory()->create(['user_id' => $owner->id, 'payment_method_id' => $inactive->id, 'voided_at' => now()]);
        Receipt::factory()->create(['user_id' => $owner->id, 'payment_method_id' => $inactive->id, 'created_at' => '2026-10-29 12:00:01']);
        $this->travelTo(now()->setDate(2026, 9, 30));
        Audit::log($owner->id, 'save', 'company', $company->id, [], $company->id);
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        Audit::log($owner->id, 'save', 'company', $company->id, [], $company->id);
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
        Audit::log($owner->id, 'save', 'company', $company->id, [], $company->id);

        $response = $this->actingAs($owner)->get(route('workspace.settings'))->assertOk();
        $response->assertViewHas('usersTotal', 5)->assertViewHas('usersActive', 4)->assertViewHas('usersInactive', 1)->assertViewHas('usersNewMonth', 4)->assertViewHas('usersNeverLoggedIn', 1)->assertViewHas('usersRecentCount', 2);
        $response->assertViewHas('methodsTotal', 3)->assertViewHas('methodsActive', 2)->assertViewHas('methodsInactive', 1)->assertViewHas('methodsUsedMonth', 1)->assertViewHas('auditMonth', 2)->assertViewHas('auditToday', 1);
        $this->assertSame(2, $response->viewData('methods')->first()->confirmed_month_count);
        $this->assertSame($used->id, $response->viewData('methods')->first()->id);
        $this->assertSame(4, $response->viewData('roles')['user']['total']);
        $this->assertSame(3, $response->viewData('roles')['user']['active']);
        $this->assertCount(3, $response->viewData('recentLogs'));
        $response->assertSee('Resumen de ajustes')->assertSee($owner->name)->assertDontSee($owner->name, false)->assertDontSee($used->name)->assertDontSee('Métodos de pago')->assertDontSee('name="address"', false);
    }

    public function test_settings_obeys_company_scope_and_keeps_management_data_hidden_from_advisors(): void
    {
        $local = Dealership::factory()->create();
        $foreign = Dealership::factory()->create();
        $manager = $this->user('dealership_admin', $local->id);
        $advisor = $this->user('user', $local->id);
        $foreignOwner = $this->user('user', $foreign->id, ['name' => 'USUARIO RESERVADO', 'last_login_at' => now()]);
        $admin = $this->user();
        PaymentMethod::create(['dealership_id' => $local->id, 'name' => 'Método local', 'active' => true]);
        PaymentMethod::create(['dealership_id' => $foreign->id, 'name' => 'METODO RESERVADO', 'active' => true]);
        Audit::log($manager->id, 'save', 'company', $local->id, [], $local->id);
        Audit::log($foreignOwner->id, 'save', 'company', $foreign->id, [], $foreign->id);

        $this->actingAs($manager)->get(route('workspace.settings', ['dealership_id' => $foreign->id]))->assertOk()->assertViewHas('usersTotal', 2)->assertViewHas('methodsTotal', 1)->assertViewHas('auditMonth', 1)->assertDontSee('USUARIO RESERVADO')->assertDontSee('METODO RESERVADO');
        $this->actingAs($admin)->get(route('workspace.settings'))->assertOk()->assertViewHas('usersTotal', 4)->assertViewHas('methodsTotal', 8)->assertViewHas('auditMonth', 2)->assertDontSee('METODO RESERVADO');
        $this->get(route('workspace.settings', ['dealership_id' => $local->id]))->assertOk()->assertViewHas('usersTotal', 2)->assertViewHas('methodsTotal', 1)->assertDontSee('METODO RESERVADO');

        $response = $this->actingAs($advisor)->get(route('workspace.settings', ['dealership_id' => $foreign->id]))->assertOk()->assertSee('Mi cuenta')->assertSee('Asesor')->assertDontSee('Usuarios registrados')->assertDontSee('Últimos cambios registrados')->assertDontSee('Nuevo usuario')->assertDontSee('USUARIO RESERVADO')->assertDontSee('METODO RESERVADO');
        $this->assertArrayNotHasKey('usersTotal', $response->original->getData());
        $this->assertArrayNotHasKey('recentLogs', $response->original->getData());
    }

    public function test_general_settings_are_removed_from_navigation_and_cannot_update_company_data(): void
    {
        $company = Dealership::factory()->create(['name' => 'Empresa original']);
        $admin = $this->user();

        $this->actingAs($admin)->withSession(['dealership_id' => $company->id])
            ->get(route('workspace.settings'))->assertOk()->assertSee('Mi cuenta')
            ->assertDontSee('Configuración general')->assertDontSee('/ajustes/general');
        $this->get('/ajustes/general')->assertNotFound();
        $this->put(route('workspace.settings'), ['company_id' => $company->id, 'name' => 'Cambio rechazado'])->assertStatus(405);

        $this->assertDatabaseHas('dealerships', ['id' => $company->id, 'name' => 'Empresa original']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_settings_require_login_and_empty_administration_shows_zero_activity(): void
    {
        $this->get(route('workspace.settings'))->assertRedirect(route('login'));
        $manager = $this->user('dealership_admin', Dealership::factory()->create()->id);

        $this->actingAs($manager)->get(route('workspace.settings'))->assertOk()->assertViewHas('usersTotal', 1)->assertViewHas('usersRecentCount', 0)->assertViewHas('methodsTotal', 0)->assertViewHas('auditMonth', 0)->assertDontSee('Métodos de pago')->assertDontSee('Ver auditoría')->assertDontSee('Últimos cambios registrados')->assertDontSee('Operaciones registradas este mes')->assertDontSee('href="'.route('audit').'"', false);
    }
}
