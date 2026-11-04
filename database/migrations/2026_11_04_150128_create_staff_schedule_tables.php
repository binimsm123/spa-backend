<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_hours', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_user_id')->constrained('business_users')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['business_user_id', 'weekday']);
        });

        Schema::create('staff_leaves', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_user_id')->constrained('business_users')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['business_user_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_leaves');
        Schema::dropIfExists('staff_hours');
    }
};
