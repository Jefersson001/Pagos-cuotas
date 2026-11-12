<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Remittance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Remittance> */
class RemittanceFactory extends Factory
{
    protected $model = Remittance::class;

    public function definition(): array
    {
        return ['token' => fake()->uuid(), 'dealership_id' => Dealership::factory(), 'concessionaire_id' => fn (array $attributes) => Client::factory()->concessionaire()->create(['dealership_id' => $attributes['dealership_id']])->id, 'recorded_by' => User::factory(), 'payment_method_id' => fn (array $attributes) => PaymentMethod::create(['dealership_id' => $attributes['dealership_id'], 'name' => 'Transferencia', 'active' => true])->id, 'payment_date' => today(), 'reference' => fake()->uuid(), 'amount' => '100.00', 'proof_path' => 'remittances/test.pdf', 'status' => 'pending'];
    }
}
