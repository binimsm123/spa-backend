<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('staff_hours', function (Blueprint $table) {
            $table->time('lunch_starts_at')->nullable();
            $table->time('lunch_ends_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_hours', function (Blueprint $table) {
            $table->dropColumn(['lunch_starts_at', 'lunch_ends_at']);
        });
    }
};
