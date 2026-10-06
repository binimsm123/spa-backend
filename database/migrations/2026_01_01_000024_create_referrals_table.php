<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('referral_code_id')->constrained('referral_codes')->cascadeOnDelete();
            $table->foreignUlid('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
