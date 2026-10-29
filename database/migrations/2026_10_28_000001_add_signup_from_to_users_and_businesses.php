<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('signup_from', 16)->nullable()->after('is_active')->index();
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('signup_from', 16)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropIndex(['signup_from']);
            $table->dropColumn('signup_from');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['signup_from']);
            $table->dropColumn('signup_from');
        });
    }
};
