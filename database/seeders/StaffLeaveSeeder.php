<?php

namespace Database\Seeders;

use App\Models\BusinessUser;
use App\Models\StaffLeave;
use Illuminate\Database\Seeder;

class StaffLeaveSeeder extends Seeder
{
    public function run(): void
    {
        BusinessUser::query()->where('role', 'staff')->where('is_active', true)->doesntHave('leaves')
            ->each(fn (BusinessUser $membership) => StaffLeave::factory()->for($membership, 'businessMembership')->create());
    }
}
