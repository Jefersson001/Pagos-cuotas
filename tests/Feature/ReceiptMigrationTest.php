<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptMigrationTest extends TestCase
{
    public function test_existing_confirmed_and_voided_payments_keep_their_amounts_and_history(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        RefreshDatabaseState::$migrated = false;
        try {
            $paths = collect(glob(database_path('migrations/*.php')))->reject(fn ($p) => str_contains($p, 'create_receipts_ledger'))->map(fn ($p) => 'database/migrations/'.basename($p))->all();
            $this->artisan('migrate:fresh', ['--path' => $paths, '--seed' => true, '--force' => true])->assertExitCode(0);
            $user = User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);
            $client = Client::create(['user_id' => $user->id, 'name' => 'Cliente anterior', 'document' => 'LEGACY', 'phone' => '0412']);
            $order = DB::table('orders')->insertGetId(['token' => (string) Str::uuid(), 'user_id' => $user->id, 'dealership_id' => $client->dealership_id, 'client_id' => $client->id, 'description' => 'Venta anterior', 'total' => '500.00', 'initial_amount' => '100.00', 'frequency' => 'monthly', 'created_at' => '2026-01-15 12:00:00', 'updated_at' => '2026-01-15 12:00:00']);
            $base = ['order_id' => $order, 'user_id' => $user->id, 'dealership_id' => $client->dealership_id, 'recorded_by' => $user->id, 'payment_method_id' => PaymentMethod::first()->id, 'created_at' => '2026-01-15 12:00:00', 'updated_at' => '2026-01-15 12:00:00'];
            DB::table('payments')->insert(array_merge($base, ['token' => (string) Str::uuid(), 'amount' => '100.00', 'is_initial' => true]));
            DB::table('payments')->insert(array_merge($base, ['token' => (string) Str::uuid(), 'amount' => '30.50', 'voided_at' => '2026-01-16 12:00:00', 'voided_by' => $user->id, 'void_reason' => 'Pago anterior anulado']));
            $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_26_153346_create_receipts_ledger.php', '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('receipts', 2);
            $this->assertDatabaseCount('payments', 2);
            $this->assertSame(0, Receipt::all()->sum('available_cents'));
            $receipt = Receipt::whereNull('voided_at')->firstOrFail();
            $this->assertSame('100.00', $receipt->amount);
            $this->assertSame(10000, $receipt->applied_cents);
            $this->assertSame('2026-01-15 12:00:00', $receipt->created_at->format('Y-m-d H:i:s'));
            $this->assertSame($client->id, $receipt->client_id);
            $this->assertSame($receipt->id, $receipt->applications->first()->receipt_id);
            $voided = Receipt::whereNotNull('voided_at')->firstOrFail();
            $this->assertSame('30.50', $voided->amount);
            $this->assertSame('Pago anterior anulado', $voided->void_reason);
            $this->assertSame(0, $voided->available_cents);
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('receipts', 2);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
