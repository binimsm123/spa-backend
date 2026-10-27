<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table): void {
            $table->string('reset_token_hash', 64)->nullable()->unique();
            $table->timestamp('reset_token_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('verification_codes', function (Blueprint $table): void {
            $table->dropUnique(['reset_token_hash']);
            $table->dropColumn(['reset_token_hash', 'reset_token_expires_at']);
        });
    }
};
