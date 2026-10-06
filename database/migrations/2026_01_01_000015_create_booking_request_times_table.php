<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_request_times', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_request_id')->constrained('booking_requests')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['booking_request_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_request_times');
    }
};
