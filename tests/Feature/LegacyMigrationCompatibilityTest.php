<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adopts legacy permission tables and leaves their data intact on rollback', function (): void {
    DB::table('migrations')->insert(['migration' => '2026_09_29_075207_create_permission_tables', 'batch' => 1]);
    $tables = array_values(config('permission.table_names'));
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $migration = require database_path('migrations/2026_01_01_000034_create_permission_tables.php');

    $migration->up();
    $migration->down();

    foreach ($before as $table => $count) {
        expect(Schema::hasTable($table))->toBeTrue();
        $this->assertDatabaseCount($table, $count);
    }
});

it('adopts legacy Sanctum tokens without revoking them on migrate or rollback', function (): void {
    DB::table('migrations')->insert(['migration' => '2026_09_29_075208_create_personal_access_tokens_table', 'batch' => 1]);
    $user = User::factory()->create();
    $token = $user->createToken('legacy-client');
    $migration = require database_path('migrations/2026_01_01_000035_create_personal_access_tokens_table.php');

    $migration->up();
    $migration->down();

    $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
});

it('adopts legacy sessions without deleting existing sessions on rollback', function (): void {
    DB::table('migrations')->insert(['migration' => '2026_10_01_053405_create_sessions_table', 'batch' => 1]);
    DB::table('sessions')->insert(['id' => 'legacy-session', 'payload' => 'preserved', 'last_activity' => time()]);
    $migration = require database_path('migrations/2026_01_01_000036_create_sessions_table.php');

    $migration->up();
    $migration->down();

    $this->assertDatabaseHas('sessions', ['id' => 'legacy-session', 'payload' => 'preserved']);
});

it('rejects incomplete legacy permission tables instead of silently skipping them', function (): void {
    DB::table('migrations')->insert(['migration' => '2026_09_29_075207_create_permission_tables', 'batch' => 1]);
    config(['permission.table_names.permissions' => 'missing_permissions']);
    $migration = require database_path('migrations/2026_01_01_000034_create_permission_tables.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'incomplete');
});
