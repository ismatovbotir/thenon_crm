<?php

namespace Database\Factories;

use App\Enums\ItemUnit;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('00######'),
            'name_uz' => fake()->unique()->words(2, true),
            'name_ru' => fake()->unique()->words(2, true),
            'image_path' => null,
            'unit' => ItemUnit::Pcs,
            'step' => 1,
            'category' => null,
            'is_active' => true,
        ];
    }

    public function pack(int $step = 1): static
    {
        return $this->state(fn (array $attributes) => ['unit' => ItemUnit::Pack, 'step' => $step]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
