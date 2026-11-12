<?php

namespace Database\Factories;

use App\Models\Dealership;
use App\Models\SalesEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesEvent> */
class SalesEventFactory extends Factory
{
    protected $model = SalesEvent::class;

    public function definition(): array
    {
        return ['dealership_id' => Dealership::factory(), 'name' => 'Jornada '.fake()->unique()->uuid(), 'is_default' => false, 'active' => true];
    }
}
