<?php

use App\Models\Category;
use App\Services\BusinessService;
use Illuminate\Validation\ValidationException;

it('shares categories and syncs only the selected business', function (): void {
    $shared = Category::create(['name' => 'Massage', 'slug' => 'massage']);
    $other = Category::create(['name' => 'Facial', 'slug' => 'facial']);
    $service = app(BusinessService::class);
    $first = $service->create(['name' => 'First', 'category_ids' => [$shared->id, $other->id]]);
    $second = $service->create(['name' => 'Second', 'category_ids' => [$shared->id]]);
    expect($shared->businesses()->count())->toBe(2);
    $service->update($first, ['category_ids' => [$other->id]]);
    expect($first->categories()->pluck('categories.id')->all())->toBe([$other->id])
        ->and($shared->businesses()->pluck('businesses.id')->all())->toBe([$second->id]);
});

it('automatically links service categories without duplicating associations', function (): void {
    $category = Category::create(['name' => 'Massage', 'slug' => 'massage']);
    [$business, $service] = createBusinessWithService();
    $service->update(['category_id' => $category->id]);
    $business->services()->create(['category_id' => $category->id, 'name' => 'Other', 'slug' => 'other', 'status' => 'active']);
    expect($category->businesses()->count())->toBe(1);
});

it('rolls back business changes when removing a category used by services', function (): void {
    $category = Category::create(['name' => 'Massage', 'slug' => 'massage']);
    [$business, $service] = createBusinessWithService();
    $service->update(['category_id' => $category->id]);
    $originalName = $business->name;
    expect(fn () => app(BusinessService::class)->update($business, ['name' => 'Changed', 'category_ids' => []]))
        ->toThrow(ValidationException::class);
    expect($business->fresh()->name)->toBe($originalName)
        ->and($business->categories()->count())->toBe(1);
});
