<?php

namespace Database\Seeders;

use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        Role::firstOrCreate(['name' => 'dealership_admin']);
        foreach (['Efectivo', 'Transferencia', 'Pago Móvil', 'Zelle', 'Divisas', 'Otro'] as $name) {
            PaymentMethod::firstOrCreate(['name' => $name, 'dealership_id' => Dealership::orderBy('id')->value('id')]);
        }
    }
}
