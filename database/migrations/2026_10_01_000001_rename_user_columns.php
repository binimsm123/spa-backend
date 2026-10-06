<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('mobile_number', 'mobile');
            $table->renameColumn('avatar_path', 'image');
            $table->renameColumn('reward_points_balance', 'reward_points');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('mobile', 'mobile_number');
            $table->renameColumn('image', 'avatar_path');
            $table->renameColumn('reward_points', 'reward_points_balance');
        });
    }
};
