<?php

namespace Database\Factories;

use App\Models\StaffHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffHour>
 */
class StaffHourFactory extends Factory
{
    public function definition(): array
    {
        return ['weekday' => fake()->numberBetween(0, 6), 'opens_at' => '09:00', 'closes_at' => '17:00', 'is_closed' => false];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => ['is_closed' => true, 'opens_at' => null, 'closes_at' => null]);
    }
}
