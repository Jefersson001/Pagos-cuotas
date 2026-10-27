<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DealershipMigrationTest extends TestCase
{
    public function test_existing_records_are_preserved_and_assigned_to_the_principal_dealership(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        RefreshDatabaseState::$migrated = false;
        try {
            $this->artisan('migrate:fresh', ['--path' => ['database/migrations/0001_01_01_000000_create_users_table.php', 'database/migrations/0001_01_01_000001_create_cache_table.php', 'database/migrations/0001_01_01_000002_create_jobs_table.php', 'database/migrations/2026_10_26_000001_create_sales_tables.php'], '--force' => true])->assertExitCode(0);
            $adminRole = DB::table('roles')->insertGetId(['name' => 'admin']);
            $userRole = DB::table('roles')->insertGetId(['name' => 'user']);
            $global = DB::table('users')->insertGetId(['name' => 'General', 'email' => 'global@example.test', 'password' => Hash::make('test-password'), 'role_id' => $adminRole]);
            $owner = DB::table('users')->insertGetId(['name' => 'Asesor', 'email' => 'advisor@example.test', 'password' => Hash::make('test-password'), 'role_id' => $userRole]);
            $client = DB::table('clients')->insertGetId(['user_id' => $owner, 'name' => 'Cliente anterior', 'document' => 'LEGACY', 'phone' => '0412']);
            $order = DB::table('orders')->insertGetId(['token' => 'legacy-order', 'user_id' => $owner, 'client_id' => $client, 'description' => 'Venta anterior', 'total' => '500.00', 'initial_amount' => '100.00', 'frequency' => 'monthly']);
            $schedule = DB::table('installment_schedules')->insertGetId(['order_id' => $order, 'number' => 1, 'due_date' => '2026-01-01', 'amount' => '400.00']);
            $method = DB::table('payment_methods')->insertGetId(['name' => 'Efectivo']);
            $payment = DB::table('payments')->insertGetId(['token' => 'legacy-payment', 'order_id' => $order, 'user_id' => $owner, 'recorded_by' => $global, 'payment_method_id' => $method, 'amount' => '100.00', 'is_initial' => true]);
            DB::table('audit_logs')->insert(['user_id' => $global, 'action' => 'create', 'entity' => 'order', 'entity_id' => $order]);
            $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_26_103234_add_dealership_segmentation.php', '--force' => true])->assertExitCode(0);
            $principal = DB::table('dealerships')->where('code', 'PRINCIPAL')->value('id');
            $this->assertDatabaseHas('users', ['id' => $global, 'dealership_id' => null]);
            $this->assertDatabaseHas('users', ['id' => $owner, 'dealership_id' => $principal]);
            $this->assertDatabaseHas('clients', ['id' => $client, 'dealership_id' => $principal, 'name' => 'Cliente anterior']);
            $this->assertDatabaseHas('orders', ['id' => $order, 'dealership_id' => $principal, 'total' => '500.00']);
            $this->assertDatabaseHas('payments', ['id' => $payment, 'dealership_id' => $principal, 'amount' => '100.00', 'recorded_by' => $global]);
            $this->assertDatabaseHas('installment_schedules', ['id' => $schedule, 'amount' => '400.00']);
            $this->assertDatabaseHas('payment_methods', ['id' => $method, 'dealership_id' => $principal]);
            $this->assertDatabaseHas('audit_logs', ['entity_id' => $order, 'dealership_id' => $principal]);
            $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
            $this->assertFalse(Schema::hasTable('dealerships'));
            $this->assertDatabaseCount('payments', 1);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
