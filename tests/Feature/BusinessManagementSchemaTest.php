<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('upgrades existing services without losing their legacy images or prices', function (): void {
    config(['database.connections.management_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $originalDefault = DB::getDefaultConnection();
    $originalSchema = Schema::getFacadeRoot();
    DB::setDefaultConnection('management_upgrade');
    $connection = DB::connection();
    $schema = $connection->getSchemaBuilder();
    Schema::swap($schema);
    try {
        $schema->create('businesses', fn (Blueprint $table) => $table->ulid('id')->primary());
        $schema->create('services', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->string('image_path')->nullable();
        });
        $schema->create('service_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('service_id');
            $table->integer('price_minor');
        });
        $business = (string) Str::ulid();
        $service = (string) Str::ulid();
        $connection->table('businesses')->insert(['id' => $business]);
        $connection->table('services')->insert(['id' => $service, 'business_id' => $business, 'image_path' => 'existing/catalog.jpg']);
        $connection->table('service_prices')->insert(['id' => (string) Str::ulid(), 'service_id' => $service, 'price_minor' => 125000]);
        $migration = require database_path('migrations/2026_10_27_000004_create_business_management_tables.php');
        $migration->up();
        expect($connection->table('service_photos')->where('service_id', $service)->value('path'))->toBe('existing/catalog.jpg')
            ->and($connection->table('service_prices')->value('price_minor'))->toBe(125000)
            ->and($schema->hasColumns('business_staff', ['name', 'job_title', 'mobile_number', 'avatar_path', 'deleted_at']))->toBeTrue();
        $migration->down();
        expect($connection->table('services')->value('image_path'))->toBe('existing/catalog.jpg')
            ->and($connection->table('service_prices')->count())->toBe(1);
    } finally {
        Schema::swap($originalSchema);
        DB::setDefaultConnection($originalDefault);
        DB::purge('management_upgrade');
    }
});
