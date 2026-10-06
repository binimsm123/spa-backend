<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('service_name_snapshot');
            $table->unsignedInteger('duration_minutes_snapshot');
            $table->unsignedInteger('max_people_snapshot')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->char('currency', 3)->default('NPR');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->index(['booking_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_items');
    }
};
