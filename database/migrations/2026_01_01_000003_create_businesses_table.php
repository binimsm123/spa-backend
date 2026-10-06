<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('search_name')->nullable();
            $table->text('about')->nullable();
            $table->string('hero_image_path')->nullable();
            $table->string('phone_number', 32)->nullable();
            $table->boolean('is_verified')->default(false)->index();
            $table->boolean('is_insured')->default(false);
            $table->boolean('is_online')->default(false)->index();
            $table->string('status')->default('pending')->index();
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Active catalog ordering per ERD (status, is_verified, name, id)
            $table->index(['status', 'is_verified', 'name', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
