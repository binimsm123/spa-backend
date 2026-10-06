<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('branch_name');
            $table->string('address')->nullable();
            $table->string('city')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64)->default('Asia/Kathmandu');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['business_id', 'branch_name']);
            $table->index(['location_id', 'is_active', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_locations');
    }
};
