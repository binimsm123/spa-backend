<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_configurations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->decimal('points_per_rupee', 8, 4)->default(1);
            $table->string('basis')->default('subtotal'); // subtotal | post_discount | tax_inclusive | total
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_configurations');
    }
};
