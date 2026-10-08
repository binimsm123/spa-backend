<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['vat_pan_number', 'registration_number', 'owner_identity_type', 'search_name']);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('vat_pan_number', 64)->nullable();
            $table->string('registration_number', 120)->nullable();
            $table->string('owner_identity_type', 32)->nullable();
            $table->string('search_name', 255)->nullable();
        });
    }
};
