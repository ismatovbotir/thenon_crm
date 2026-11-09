<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\LocationCutoff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationCutoff>
 */
class LocationCutoffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'weekday' => 1,
            'is_open' => true,
            'cutoff_time' => '18:00',
        ];
    }
}
