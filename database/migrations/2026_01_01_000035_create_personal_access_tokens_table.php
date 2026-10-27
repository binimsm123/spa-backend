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
            if (! Schema::hasColumns('personal_access_tokens', [
                'id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities',
                'last_used_at', 'expires_at', 'created_at', 'updated_at',
            ])) {
                throw new RuntimeException('The legacy access-token migration is recorded, but its table is incomplete. Restore its schema before migrating.');
            }

            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->ulidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
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

        Schema::dropIfExists('personal_access_tokens');
    }

    private function legacyMigrationApplied(): bool
    {
        // These migration files were renamed after existing installations had run them.
        return DB::table(config('database.migrations.table', 'migrations'))
            ->where('migration', '2026_09_29_075208_create_personal_access_tokens_table')->exists();
    }
};
