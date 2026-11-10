<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'dealership_id' => fn (array $attributes): int => User::findOrFail($attributes['user_id'])->dealership_id ?? Dealership::firstOrFail()->id,
            'customer_kind' => 'person',
            'name' => fake()->name(),
            'document' => fake()->unique()->numerify('V-########'),
            'phone' => '04121234567',
            'active' => true,
        ];
    }

    public function concessionaire(): static
    {
        return $this->state(fn (array $attributes): array => ['customer_kind' => 'dealership', 'name' => fake()->company()]);
    }
}
