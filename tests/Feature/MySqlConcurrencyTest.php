<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    public static function creditEntryPoints(): array
    {
        return ['módulo de pagos' => [false], 'desde venta' => [true]];
    }

    #[DataProvider('creditEntryPoints')]
    public function test_two_sales_cannot_spend_the_same_client_credit_simultaneously(bool $fromSale): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requiere phpunit.mysql.xml y una base aislada.');
        }
        $this->assertStringEndsWith('_test', config('database.connections.mysql.database'));
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->assertExitCode(0);
        $user = User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);
        $client = Client::create(['user_id' => $user->id, 'name' => 'Saldo concurrente', 'document' => 'CREDIT', 'phone' => '0412']);
        $method = PaymentMethod::first()->id;
        $orders = [];
        for ($n = 0; $n < 2; $n++) {
            $orders[] = app(SalesService::class)->create($user, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta concurrente', 'items' => [['description' => 'Moto', 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '0', 'payment_method_id' => $method, 'installment_count' => 1, 'frequency' => 'monthly', 'first_due' => '2026-12-01']);
        }
        $receipt = app(ReceiptService::class)->receive($user, $client->id, ['token' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method_id' => $method]);
        DB::beginTransaction();
        DB::table('clients')->where('id', $client->id)->lockForUpdate()->first();
        $workers = [];
        try {
            foreach ($orders as $order) {
                $arguments = [PHP_BINARY, base_path('tests/Support/payment-worker.php'), (string) $user->id, (string) $order->id, (string) $method, (string) $receipt->id];
                if ($fromSale) {
                    $arguments[] = 'credit-from-sale';
                }
                $worker = new Process($arguments, base_path(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password')]);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')));
            }
        } finally {
            DB::commit();
        }
        $outputs = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $outputs[] = trim(str_replace('ready', '', $worker->getOutput()));
        }
        sort($outputs);
        $this->assertSame(['paid', 'rejected'], $outputs);
        $this->assertSame(2000, $receipt->fresh()->available_cents);
        $this->assertSame(1, $receipt->applications()->count());
    }

    public function test_simultaneous_payments_cannot_exceed_balance(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requiere phpunit.mysql.xml y una base aislada.');
        }
        $this->assertStringEndsWith('_test', config('database.connections.mysql.database'));
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->assertExitCode(0);
        $u = User::factory()->create(['role_id' => Role::where('name', 'user')->first()->id]);
        $c = Client::create(['user_id' => $u->id, 'name' => 'Concurrency', 'document' => 'test', 'phone' => 'test']);
        $method = PaymentMethod::first()->id;
        $o = app(SalesService::class)->create($u, ['token' => (string) Str::uuid(), 'client_id' => $c->id, 'description' => 'Concurrent test', 'items' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '0', 'payment_method_id' => $method, 'installment_count' => 1, 'frequency' => 'monthly', 'first_due' => '2026-12-01']);
        DB::beginTransaction();
        DB::table('orders')->where('id', $o->id)->lockForUpdate()->first();
        $workers = [];
        try {
            for ($n = 0; $n < 2; $n++) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/payment-worker.php'), (string) $u->id, (string) $o->id, (string) $method], base_path(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password')]);
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            foreach ($workers as $p) {
                $this->assertTrue($p->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')));
            }
        } finally {
            DB::commit();
        }
        $outputs = [];
        foreach ($workers as $p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            $outputs[] = trim(str_replace('ready', '', $p->getOutput()));
        }
        sort($outputs);
        $this->assertSame(['paid', 'rejected'], $outputs);
        $this->assertSame(2000, $o->fresh()->balance_cents);
        $this->assertSame(1, $o->payments()->count());
    }
}
