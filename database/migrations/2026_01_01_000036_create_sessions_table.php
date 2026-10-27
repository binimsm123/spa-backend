<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->legacyMigrationApplied()) {
            if (! Schema::hasColumns('sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'])) {
                throw new RuntimeException('The legacy sessions migration is recorded, but its table is incomplete. Restore its schema before migrating.');
            }

            // The old Laravel session migration used numeric IDs; users now use ULIDs.
            if (in_array(Schema::getColumnType('sessions', 'user_id'), ['bigint', 'int', 'integer'], true)) {
                Schema::table('sessions', function (Blueprint $table): void {
                    $table->ulid('user_id')->nullable()->change();
                });
            }

            return;
        }

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // These tables remain owned by their original migration on upgraded databases.
        if ($this->legacyMigrationApplied()) {
            return;
        }

        Schema::dropIfExists('sessions');
    }

    private function legacyMigrationApplied(): bool
    {
        // These migration files were renamed after existing installations had run them.
        return DB::table(config('database.migrations.table', 'migrations'))
            ->where('migration', '2026_10_01_053405_create_sessions_table')->exists();
    }
};
