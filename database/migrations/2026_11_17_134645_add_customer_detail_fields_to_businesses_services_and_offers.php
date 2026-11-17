<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('years_of_experience')->nullable();
            $table->json('gallery_image_urls')->nullable();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->json('video_urls')->nullable();
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->json('image_urls')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn('image_urls');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('video_urls');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['years_of_experience', 'gallery_image_urls']);
        });
    }
};
