<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'token_hash' => ApiToken::hash(fake()->unique()->sha256()),
        ];
    }
}
