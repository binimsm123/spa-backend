<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_redemptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->ulid('idempotency_key')->unique();
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('points_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->useCurrent();
            $table->string('status')->default('reserved'); // reserved | applied | released
            $table->timestamps();

            $table->index(['user_id', 'offer_id', 'status']);
            $table->index(['offer_id', 'redeemed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_redemptions');
    }
};
