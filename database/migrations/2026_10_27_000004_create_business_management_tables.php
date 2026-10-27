<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_photos', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['service_id', 'position', 'id']);
        });

        // Keep existing catalog images addressable through the new photo API.
        DB::table('services')->whereNotNull('image_path')->where('image_path', '!=', '')
            ->orderBy('id')->chunkById(200, function ($services): void {
                foreach ($services as $service) {
                    DB::table('service_photos')->insert([
                        'id' => (string) Str::ulid(), 'service_id' => $service->id,
                        'path' => $service->image_path, 'position' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });

        Schema::create('business_staff', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('job_title', 80);
            $table->string('mobile_number', 16);
            $table->string('avatar_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'deleted_at', 'mobile_number']);
            $table->index(['business_id', 'name', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_staff');
        Schema::dropIfExists('service_photos');
    }
};
