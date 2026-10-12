<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Validation\ValidationException;

class BusinessCategoryService
{
    /** @param array<string, mixed> $data Validated business attributes. */
    public function save(Business $business, array $data): void
    {
        if (! array_key_exists('category_ids', $data)) {
            return;
        }

        $categoryIds = $data['category_ids'];
        if (! $business->wasRecentlyCreated && $business->services()->whereNotNull('category_id')->whereNotIn('category_id', $categoryIds)->exists()) {
            throw ValidationException::withMessages(['category_ids' => 'Categories used by existing services cannot be removed.']);
        }

        $business->categories()->sync($categoryIds);
    }
}
