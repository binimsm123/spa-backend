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
            $table->foreignUlid('business_location_id')->constrained('business_locations')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3)->default('NPR');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(
                ['service_id', 'business_location_id', 'is_current', 'starts_at', 'ends_at'],
                'service_prices_scope_dates_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
