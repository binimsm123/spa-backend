<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->boolean('is_current')->default(false);
            $table->timestamp('selected_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'location_id']);
            $table->index(['user_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_locations');
    }
};
