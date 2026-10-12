<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_category', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['business_id', 'category_id']);
            $table->index(['category_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_category');
    }
};
