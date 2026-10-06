<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->string('tag', 64)->nullable();
            $table->text('comments')->nullable();
            $table->boolean('is_hidden')->default(false)->index();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignUlid('hidden_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'is_hidden', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
