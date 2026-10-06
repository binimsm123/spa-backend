<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('referral_id')->constrained('referrals')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reward_type'); // points | credit | discount
            $table->unsignedBigInteger('value')->default(0);
            $table->char('currency', 3)->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
