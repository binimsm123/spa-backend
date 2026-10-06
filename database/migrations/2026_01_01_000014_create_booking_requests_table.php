<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('business_location_id')->constrained('business_locations')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedInteger('people_count')->default(1);
            $table->date('requested_date');
            $table->string('timezone', 64)->default('Asia/Kathmandu');
            $table->string('status')->default('pending')->index();
            $table->ulid('idempotency_key')->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'requested_date']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
    }
};
