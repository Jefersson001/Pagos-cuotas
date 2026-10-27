<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Receipt> */
class ReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['token' => (string) Str::uuid(), 'user_id' => User::factory(), 'dealership_id' => fn (array $a) => User::findOrFail($a['user_id'])->dealership_id ?? Dealership::firstOrFail()->id, 'client_id' => fn (array $a) => Client::create(['user_id' => $a['user_id'], 'dealership_id' => $a['dealership_id'], 'name' => fake()->name(), 'document' => (string) Str::uuid(), 'phone' => '04121234567'])->id, 'recorded_by' => fn (array $a) => $a['user_id'], 'payment_method_id' => fn (array $a) => PaymentMethod::where('dealership_id', $a['dealership_id'])->firstOrFail()->id, 'amount' => '100.00'];
    }
}
