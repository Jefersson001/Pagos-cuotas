<?php

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'ventas_programada_browser') {
    exit(3);
}
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$password = Str::password(20);
User::create(['name' => 'Browser Admin', 'username' => 'browser_admin', 'email' => 'browser@example.test', 'password' => $password, 'role_id' => Role::where('name', 'admin')->first()->id]);
$advisor = User::create(['name' => 'Asesor principal', 'username' => 'advisor_principal', 'email' => 'advisor-principal@example.test', 'password' => Str::password(20), 'role_id' => Role::where('name', 'user')->first()->id]);
foreach (range(1, 21) as $number) {
    Client::create(['user_id' => $advisor->id, 'name' => 'Cliente paginación '.str_pad($number, 2, '0', STR_PAD_LEFT), 'document' => 'BROWSER-PAGE-'.$number, 'phone' => '04121234567']);
}
Product::create(['user_id' => $advisor->id, 'name' => 'Equipo de prueba', 'price' => '500.00', 'initial_amount' => '100.00']);
Product::create(['user_id' => $advisor->id, 'name' => 'Moto Bera saldo', 'price' => '400.00', 'initial_amount' => '0.00']);
$extra = [];
if (($argv[1] ?? null) === 'dashboard') {
    $late = Client::create(['user_id' => $advisor->id, 'name' => 'Cliente con vencido', 'document' => 'DASH-LATE', 'phone' => '04121234567']);
    $applicable = Client::create(['user_id' => $advisor->id, 'name' => 'Cliente con saldo por aplicar', 'document' => 'DASH-APPLY', 'phone' => '04121234567']);
    $credit = Client::create(['user_id' => $advisor->id, 'name' => 'Cliente solo saldo a favor', 'document' => 'DASH-CREDIT', 'phone' => '04121234567']);
    $paid = Client::create(['user_id' => $advisor->id, 'name' => 'Cliente cuenta saldada', 'document' => 'DASH-PAID', 'phone' => '04121234567']);
    $sale = function ($actor, $client, $total, $initial = '0', $past = false) {
        return app(SalesService::class)->create($actor, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta resumen', 'items' => [['description' => 'Moto', 'quantity' => 1, 'unit_price' => $total]], 'initial_amount' => $initial, 'installment_count' => 3, 'frequency' => 'monthly', 'first_due' => ($past ? today()->subDay() : today()->addMonth())->toDateString()]);
    };
    $receive = function ($actor, $client, $amount) {
        $method = PaymentMethod::firstOrCreate(['dealership_id' => $client->dealership_id, 'name' => 'Caja resumen']);

        return app(ReceiptService::class)->receive($actor, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id]);
    };
    $sale($advisor, $late, '1000.00', '100.00', true);
    $sale($advisor, $applicable, '500.00');
    $receive($advisor, $applicable, '200.00');
    $receive($advisor, $credit, '150.00');
    $paidOrder = $sale($advisor, $paid, '400.00');
    $paidReceipt = $receive($advisor, $paid, '400.00');
    app(ReceiptService::class)->apply($advisor, $paidReceipt, $paidOrder->id, ['token' => (string) Str::uuid(), 'amount' => '400.00']);
    $other = Dealership::create(['name' => 'Bera resumen externo', 'code' => 'DASH-OTHER', 'active' => true]);
    $foreign = User::create(['name' => 'Asesor externo', 'email' => 'dash-foreign@example.test', 'password' => Str::password(20), 'role_id' => Role::where('name', 'user')->value('id'), 'dealership_id' => $other->id]);
    $foreignClient = Client::create(['user_id' => $foreign->id, 'name' => 'Cliente concesionario externo', 'document' => 'DASH-OTHER', 'phone' => '04121234567']);
    Product::create(['user_id' => $foreign->id, 'dealership_id' => $other->id, 'name' => 'Moto externa', 'price' => '900.00', 'initial_amount' => '0']);
    $sale($foreign, $foreignClient, '900.00');
    $receive($foreign, $foreignClient, '20.00');
    $extra = ['principal' => $advisor->dealership_id, 'other' => $other->id, 'late' => $late->id];
}
echo json_encode(array_merge(['email' => 'browser@example.test', 'password' => $password],$extra));
