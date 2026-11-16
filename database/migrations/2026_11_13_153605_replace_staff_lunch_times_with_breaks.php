<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_hours', function (Blueprint $table): void {
            $table->json('breaks')->nullable();
        });

        $connection = Schema::getConnection();
        $connection->table('staff_hours')->orderBy('id')->chunkById(100, function (Collection $hours) use ($connection): void {
            foreach ($hours as $hour) {
                $breaks = ! $hour->is_closed && $hour->lunch_starts_at !== null && $hour->lunch_ends_at !== null
                    ? [['name' => 'Lunch', 'starts_at' => substr($hour->lunch_starts_at, 0, 5), 'ends_at' => substr($hour->lunch_ends_at, 0, 5)]]
                    : [];
                $connection->table('staff_hours')->where('id', $hour->id)->update(['breaks' => json_encode($breaks, JSON_THROW_ON_ERROR)]);
            }
        });

        Schema::table('staff_hours', function (Blueprint $table): void {
            $table->dropColumn(['lunch_starts_at', 'lunch_ends_at']);
        });
    }

    /** Rollback to the legacy schema can retain only the first break per day. */
    public function down(): void
    {
        Schema::table('staff_hours', function (Blueprint $table): void {
            $table->time('lunch_starts_at')->nullable();
            $table->time('lunch_ends_at')->nullable();
        });

        $connection = Schema::getConnection();
        $connection->table('staff_hours')->orderBy('id')->chunkById(100, function (Collection $hours) use ($connection): void {
            foreach ($hours as $hour) {
                $firstBreak = json_decode($hour->breaks ?? '[]', true, flags: JSON_THROW_ON_ERROR)[0] ?? null;
                $connection->table('staff_hours')->where('id', $hour->id)->update([
                    'lunch_starts_at' => $firstBreak['starts_at'] ?? null,
                    'lunch_ends_at' => $firstBreak['ends_at'] ?? null,
                ]);
            }
        });

        Schema::table('staff_hours', function (Blueprint $table): void {
            $table->dropColumn('breaks');
        });
    }
};
