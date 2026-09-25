<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class EngineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(), 'server_url' => 'http://127.0.0.1:8000',
            'business_id' => fn () => Business::create(['name' => fake()->company()])->id,
            'enabled' => true,
        ];
    }
}
