<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('repairs legacy business columns without changing existing rows or preexisting field values', function (array $existing): void {
    // Isolate the historical schema instead of deleting columns or rows from the
    // application's testing database (DDL commits implicitly on MySQL).
    config(['database.connections.business_schema_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $connection = DB::connection('business_schema_upgrade');
    $originalSchema = Schema::getFacadeRoot();
    $schema = $connection->getSchemaBuilder();
    Schema::swap($schema);

    try {
        $schema->create('businesses', function (Blueprint $table) use ($existing): void {
            $table->string('id')->primary();
            $table->string('name');
            foreach (array_keys($existing) as $column) {
                $table->integer($column)->default(0);
            }
        });
        $connection->table('businesses')->insert(['id' => 'existing-business', 'name' => 'Preserve this business', ...$existing]);
        $migration = require database_path('migrations/2026_10_27_000003_add_missing_business_service_columns.php');

        $migration->up();
        $migration->up(); // A retry or partially upgraded installation is safe.

        $row = (array) $connection->table('businesses')->first();
        expect($row['name'])->toBe('Preserve this business');
        foreach (['home_service' => 0, 'location_service' => 1, 'staff_number' => 0, 'outlet_number' => 0] as $column => $default) {
            expect($row[$column])->toBe($existing[$column] ?? $default);
        }
        $connection->table('businesses')->insert([
            'id' => 'new-business', 'name' => 'New onboarding',
            'home_service' => true, 'location_service' => false, 'staff_number' => 6, 'outlet_number' => 2,
        ]);
        expect($connection->table('businesses')->count())->toBe(2);

        $migration->down();
        expect($schema->hasColumns('businesses', ['home_service', 'location_service', 'staff_number', 'outlet_number']))->toBeTrue();
        expect($connection->table('businesses')->count())->toBe(2);
    } finally {
        Schema::swap($originalSchema);
        DB::purge('business_schema_upgrade');
    }
})->with([
    'all service columns missing' => [[]],
    'partially upgraded table' => [['home_service' => 1, 'staff_number' => 12]],
    'current schema and existing values' => [['home_service' => 1, 'location_service' => 0, 'staff_number' => 8, 'outlet_number' => 3]],
]);
