<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table): void {
            $table->ulid('user_id')->nullable()->change();
            $table->string('mobile_number', 16)->nullable()->index();
            $table->string('registration_token_hash', 64)->nullable()->unique();
            $table->timestamp('registration_token_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('verification_codes')->whereNull('user_id')->delete();

        Schema::table('verification_codes', function (Blueprint $table): void {
            $table->dropIndex(['mobile_number']);
            $table->dropUnique(['registration_token_hash']);
            $table->dropColumn(['mobile_number', 'registration_token_hash', 'registration_token_expires_at']);
            $table->ulid('user_id')->nullable(false)->change();
        });
    }
};
