<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function completed(): static
    {
        return $this->state(['status' => OrderStatus::Completed, 'completed_at' => now()]);
    }

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'order_date' => today(),
            'status' => OrderStatus::Draft,
        ];
    }
}
