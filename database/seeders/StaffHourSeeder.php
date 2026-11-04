<?php

namespace Database\Seeders;

use App\Models\BusinessUser;
use App\Models\StaffHour;
use Illuminate\Database\Seeder;

class StaffHourSeeder extends Seeder
{
    public function run(): void
    {
        BusinessUser::query()->where('role', 'staff')->where('is_active', true)->each(function (BusinessUser $membership): void {
            foreach (range(0, 6) as $weekday) {
                $hour = StaffHour::factory()->make(['weekday' => $weekday, 'is_closed' => $weekday === 0,
                    'opens_at' => $weekday === 0 ? null : '09:00', 'closes_at' => $weekday === 0 ? null : '17:00']);
                $membership->hours()->firstOrCreate(['weekday' => $weekday], $hour->getAttributes());
            }
        });
    }
}
