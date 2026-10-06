<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_platform_sponsored')->default(false);
            $table->string('code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('discount_type'); // fixed | percentage
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('minimum_booking_amount_minor')->default(0);
            $table->char('currency', 3)->default('NPR');
            $table->unsignedBigInteger('max_discount_minor')->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('expires_at')->index();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('requires_reward_points')->default(false);
            $table->unsignedBigInteger('reward_points_cost')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at', 'expires_at', 'business_id']);
            $table->index(['service_id', 'status', 'starts_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
