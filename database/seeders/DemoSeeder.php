<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        if (User::where('email', 'asesor@example.test')->exists()) {
            return;
        }
        $password = Str::password(18);
        $user = User::create(['name' => 'Asesor de ejemplo', 'username' => 'asesor_demo', 'email' => 'asesor@example.test', 'password' => $password, 'role_id' => Role::where('name', 'user')->firstOrFail()->id]);
        foreach (['María González', 'Carlos Pérez', 'Andrea Rodríguez'] as $n => $name) {
            $c = Client::create(['user_id' => $user->id, 'name' => $name, 'document' => 'DEMO-'.($n + 1), 'phone' => 'Sin teléfono', 'notes' => 'Registro de ejemplo']);
            $order = app(SalesService::class)->create($user, ['token' => (string) Str::uuid(), 'client_id' => $c->id, 'description' => 'Pedido de ejemplo', 'items' => [['description' => 'Producto de muestra', 'quantity' => 1, 'unit_price' => '500.00']], 'initial_amount' => '100.00', 'payment_method_id' => PaymentMethod::first()->id, 'frequency' => 'monthly', 'first_due' => today()->subMonthsNoOverflow(1)->toDateString(), 'installment_count' => 4]);
            if ($n > 0) {
                app(SalesService::class)->pay($user, $order->id, ['token' => (string) Str::uuid(), 'amount' => '150.00', 'payment_method_id' => PaymentMethod::first()->id]);
            }
        }
        $this->command?->info('Usuario de ejemplo: asesor@example.test / '.$password);
    }
}
