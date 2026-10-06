<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $mobile = env('ADMIN_MOBILE_NUMBER', '9800000000');
        $password = env('ADMIN_PASSWORD', 'ChangeMe!2026');

        $admin = User::query()->updateOrCreate(
            ['mobile' => $mobile],
            [
                'display_name' => 'Platform Admin',
                'email' => env('ADMIN_EMAIL', 'admin@example.test'),
                'password' => Hash::make($password),
                'is_active' => true,
                'mobile_verified_at' => now(),
            ],
        );

        $admin->assignRole('superadmin');
    }
}
