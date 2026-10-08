<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->time('duration');
            $table->char('currency', 3)->default('NPR');
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(
                ['service_id', 'is_current', 'duration'],
                'service_prices_service_duration_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
