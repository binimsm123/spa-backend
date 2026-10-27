<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older installations ran a version of create_businesses_table that did
        // not include these fields. Fresh installs already have them.
        foreach (['home_service', 'location_service', 'staff_number', 'outlet_number'] as $column) {
            if (Schema::hasColumn('businesses', $column)) {
                continue;
            }

            Schema::table('businesses', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'home_service' => $table->boolean($column)->default(false),
                    'location_service' => $table->boolean($column)->default(true),
                    default => $table->integer($column)->default(0),
                };
            });
        }
    }

    public function down(): void
    {
        // Forward-only compatibility repair: retain these fields and their data.
        // They may have existed before this migration on a fresh or partial schema.
    }
};
