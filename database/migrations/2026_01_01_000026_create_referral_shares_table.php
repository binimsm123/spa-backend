<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_shares', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('referral_code_id')->constrained('referral_codes')->cascadeOnDelete();
            $table->string('channel', 32);

            $table->string('tracking_id')->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'referral_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_shares');
    }
};
