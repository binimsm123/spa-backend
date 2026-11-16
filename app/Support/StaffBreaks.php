<?php

namespace App\Support;

class StaffBreaks
{
    /**
     * @param  array<int, array{name?: ?string, starts_at: string, ends_at: string}>  $breaks
     * @return array<int, array{name: ?string, starts_at: string, ends_at: string}>
     */
    public static function normalize(array $breaks): array
    {
        return array_map(fn (array $break): array => [
            'name' => $break['name'] ?? null,
            'starts_at' => $break['starts_at'],
            'ends_at' => $break['ends_at'],
        ], $breaks);
    }

    /**
     * Validate intervals after their shape and time formats have been validated.
     *
     * @param  array<int, array{name?: ?string, starts_at: string, ends_at: string}>  $breaks
     * @return array<string, array<int, string>>
     */
    public static function errors(array $breaks, string $opensAt, string $closesAt, string $prefix = 'breaks'): array
    {
        $errors = [];
        if (count($breaks) > 50) {
            $errors[$prefix][] = 'A working day may have at most 50 breaks.';
        }
        $previousEnd = null;
        foreach (collect($breaks)->sortBy('starts_at') as $index => $break) {
            if ($break['starts_at'] < $opensAt) {
                $errors[$prefix.'.'.$index.'.starts_at'][] = 'A break must start within the staff working hours.';
            }
            if ($break['ends_at'] > $closesAt) {
                $errors[$prefix.'.'.$index.'.ends_at'][] = 'A break must end within the staff working hours.';
            }
            if ($previousEnd !== null && $break['starts_at'] < $previousEnd) {
                $errors[$prefix.'.'.$index.'.starts_at'][] = 'Staff breaks must not overlap.';
            }
            $previousEnd = max($previousEnd ?? $break['ends_at'], $break['ends_at']);
        }

        return $errors;
    }
}
