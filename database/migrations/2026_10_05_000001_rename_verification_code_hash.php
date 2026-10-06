<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table): void {
            $table->renameColumn('code_hash', 'code');
        });
    }

    public function down(): void
    {
        Schema::table('verification_codes', function (Blueprint $table): void {
            $table->renameColumn('code', 'code_hash');
        });
    }
};
