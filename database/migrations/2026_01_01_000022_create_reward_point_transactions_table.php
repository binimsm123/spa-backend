<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_point_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignUlid('offer_redemption_id')->nullable()->constrained('offer_redemptions')->nullOnDelete();
            $table->foreignUlid('reward_configuration_id')->nullable()->constrained('reward_configurations')->nullOnDelete();
            $table->string('type'); // earned | redeemed | refunded | expired | adjusted
            $table->bigInteger('points'); // positive earned, negative redeemed/refunded/expired
            $table->bigInteger('balance_after');
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['booking_id', 'type']);
            $table->index(['offer_redemption_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_point_transactions');
    }
};
