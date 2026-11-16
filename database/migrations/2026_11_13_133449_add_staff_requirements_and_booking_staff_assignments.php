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
        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedTinyInteger('required_staff_count')->default(1);
        });
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->unsignedTinyInteger('required_staff_count_snapshot')->default(1);
        });
        Schema::create('booking_staff', function (Blueprint $table): void {
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['booking_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_staff');
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->dropColumn('required_staff_count_snapshot');
        });
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('required_staff_count');
        });
    }
};
