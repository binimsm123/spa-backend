<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_location_id')->constrained('business_locations')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['business_location_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
    }
};
