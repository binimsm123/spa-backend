<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_request_id')->unique()->nullable()->constrained('booking_requests')->nullOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('business_location_id')->constrained('business_locations')->cascadeOnDelete();
            $table->foreignUlid('assigned_staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('appointment_date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64)->default('Asia/Kathmandu');
            $table->string('status')->default('awaiting_payment')->index();
            $table->unsignedInteger('people_count')->default(1);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tip_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('reward_points_earned')->default(0);
            $table->char('currency', 3)->default('NPR');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('cancelled_by_user_id')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'starts_at']);
            $table->index(['business_location_id', 'status', 'starts_at']);
            $table->index(['business_id', 'appointment_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
