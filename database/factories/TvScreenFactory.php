<?php

namespace Database\Factories;

use App\Models\TvScreen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TvScreen>
 */
class TvScreenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'token' => TvScreen::newToken(),
            'type' => TvScreen::TYPE_INTERNAL,
            'is_active' => true,
        ];
    }
}
