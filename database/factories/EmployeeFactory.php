<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'phone' => '+998'.fake()->unique()->numerify('9########'),
            'language' => null,
            'telegram_id' => null,
            'registered_at' => null,
            'is_active' => true,
        ];
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'language' => fake()->randomElement(Language::cases()),
            'telegram_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'registered_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
