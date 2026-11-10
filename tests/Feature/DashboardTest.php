<?php

namespace Tests\Feature;

use App\Models\Dealership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_retired_summary_redirects_to_sales_and_still_requires_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('workspace.sales'));
        $this->get(route('home'))->assertOk()->assertViewHas('modules', fn ($modules) => ! isset($modules['summary']) && count($modules) === 5);
        $this->get(route('workspace.sales'))->assertOk()->assertSee('Comparación con el mes anterior')->assertSee('Antigüedad de la deuda')->assertSee('Principales deudores');
    }

    public function test_old_summary_links_preserve_the_selected_company(): void
    {
        $company = Dealership::factory()->create();
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);

        $this->actingAs($user)->get(route('dashboard', ['dealership_id' => $company->id, 'from' => '2026-01-01']))->assertRedirect(route('workspace.sales', ['dealership_id' => $company->id]));
        $this->get(route('dashboard', ['dealership_id' => '']))->assertRedirect(route('workspace.sales'));
    }
}
