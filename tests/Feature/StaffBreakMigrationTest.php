<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves existing lunch intervals as generic breaks during schema upgrades', function (): void {
    config(['database.connections.staff_break_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $connection = DB::connection('staff_break_upgrade');
    $originalSchema = Schema::getFacadeRoot();
    $schema = $connection->getSchemaBuilder();
    Schema::swap($schema);

    try {
        $schema->create('staff_hours', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->boolean('is_closed')->default(false);
            $table->time('lunch_starts_at')->nullable();
            $table->time('lunch_ends_at')->nullable();
        });
        $connection->table('staff_hours')->insert([
            ['id' => 'a', 'is_closed' => false, 'lunch_starts_at' => '12:15:00', 'lunch_ends_at' => '13:00:00'],
            ['id' => 'b', 'is_closed' => false, 'lunch_starts_at' => null, 'lunch_ends_at' => null],
            ['id' => 'c', 'is_closed' => true, 'lunch_starts_at' => '12:00:00', 'lunch_ends_at' => '13:00:00'],
        ]);
        $migration = require database_path('migrations/2026_11_13_153605_replace_staff_lunch_times_with_breaks.php');

        $migration->up();

        expect(json_decode($connection->table('staff_hours')->where('id', 'a')->value('breaks'), true))
            ->toBe([['name' => 'Lunch', 'starts_at' => '12:15', 'ends_at' => '13:00']]);
        expect(json_decode($connection->table('staff_hours')->where('id', 'b')->value('breaks'), true))->toBe([]);
        expect(json_decode($connection->table('staff_hours')->where('id', 'c')->value('breaks'), true))->toBe([]);
        expect($schema->hasColumn('staff_hours', 'lunch_starts_at'))->toBeFalse();

        $migration->down();

        expect($connection->table('staff_hours')->where('id', 'a')->value('lunch_starts_at'))->toBe('12:15');
        expect($connection->table('staff_hours')->where('id', 'b')->value('lunch_starts_at'))->toBeNull();
        expect($schema->hasColumn('staff_hours', 'breaks'))->toBeFalse();
    } finally {
        Schema::swap($originalSchema);
        DB::purge('staff_break_upgrade');
    }
});
