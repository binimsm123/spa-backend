<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('mobile_number')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('display_name');
            $table->string('avatar_path')->nullable();
            $table->string('timezone', 64)->default('Asia/Kathmandu');
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('reward_points_balance')->default(0);
            $table->timestamp('mobile_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
