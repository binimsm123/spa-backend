<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_onboardings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('form_data');
            $table->json('documents');
            $table->string('status')->default('submitted');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_onboardings');
    }
};
