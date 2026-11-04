<?php

namespace Database\Factories;

use App\Models\StaffLeave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffLeave>
 */
class StaffLeaveFactory extends Factory
{
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('+1 day', '+1 month')->format('Y-m-d');

        return ['starts_on' => $date, 'ends_on' => $date, 'starts_at' => null, 'ends_at' => null, 'reason' => 'Personal leave'];
    }

    public function partialDay(): static
    {
        return $this->state(fn (array $attributes): array => ['starts_at' => '10:00', 'ends_at' => '12:00']);
    }
}
