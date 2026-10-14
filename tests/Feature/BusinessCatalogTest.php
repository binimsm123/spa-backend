<?php

use App\Http\Dto\Api\CatalogData;
use App\Models\Booking;
use App\Models\Category;
use App\Models\User;
use App\Services\BusinessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

function catalogBusiness(Category $category, string $city = 'Kathmandu'): array
{
    [$business, $service] = createBusinessWithService();
    $service->update(['category_id' => $category->id]);
    $business->locations()->create(['address' => 'Street 12', 'city' => $city, 'latitude' => 27.7, 'longitude' => 85.3, 'service_availability' => 'on_site']);

    return [$business, $service];
}

it('groups filtered businesses and their current priced services under categories', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$business, $service] = catalogBusiness($category);
    catalogBusiness($category, 'Pokhara');
    [$offline] = catalogBusiness($category);
    $offline->update(['is_online' => false]);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home?city=Kathmandu&category_id='.$category->id)
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.id', $category->id)
        ->assertJsonCount(1, 'data.categories.0.businesses')
        ->assertJsonPath('data.categories.0.businesses.0.id', $business->id)
        ->assertJsonPath('data.categories.0.businesses.0.services.0.id', $service->id)
        ->assertJsonPath('data.categories.0.businesses.0.services.0.price', 500000);
});

it('uses a fixed number of catalog queries as the business count increases', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    catalogBusiness($category);
    catalogBusiness($category);
    $this->actingAs(User::factory()->create(), 'sanctum');
    $preventedLazyLoading = Model::preventsLazyLoading();
    Model::preventLazyLoading();
    $queryCounts = [];

    try {
        foreach ([2, 8] as $count) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/v1/home')->assertOk()->assertJsonCount($count, 'data.categories.0.businesses');
            $queryCounts[] = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from `(businesses|business_locations|services|categories|service_prices|notifications)`/', $query['query']) === 1)->count();
            DB::disableQueryLog();
            if ($count === 2) {
                foreach (range(1, 6) as $index) {
                    catalogBusiness($category);
                }
                $otherCategory = Category::query()->create(['name' => 'Facial', 'slug' => 'facial', 'is_active' => true, 'sort_order' => 1]);
                catalogBusiness($otherCategory);
                catalogBusiness($otherCategory);
            }
        }
    } finally {
        DB::disableQueryLog();
        Model::preventLazyLoading($preventedLazyLoading);
    }

    expect($queryCounts[0])->toBe($queryCounts[1])->toBeLessThanOrEqual(6);
});

it('paginates categories and businesses independently and caps the category page size', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    catalogBusiness($category);
    catalogBusiness($category);
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home?per_page=1&businesses_per_page=1&sort=name')
        ->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.pagination.has_more', false)
        ->assertJsonPath('data.categories.0.pagination.total', 2)->assertJsonPath('data.categories.0.pagination.has_more', true)
        ->assertJsonCount(1, 'data.categories.0.businesses');
    $this->getJson('/api/v1/home?per_page=1000')->assertOk()->assertJsonPath('data.pagination.per_page', 100);
});

it('excludes inactive services and categories while retaining uncategorized services', function (): void {
    $category = Category::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
    catalogBusiness($category);
    [$business, $service] = createBusinessWithService();
    $service->update(['status' => 'paused']);
    [$uncategorized, $visible] = createBusinessWithService();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home')
        ->assertOk()->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.name', 'Uncategorized')
        ->assertJsonPath('data.categories.0.businesses.0.id', $uncategorized->id)
        ->assertJsonPath('data.categories.0.businesses.0.services.0.id', $visible->id);
});

it('limits services per business and reports omitted services', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    foreach (range(1, 2) as $index) {
        [$business] = catalogBusiness($category);
        foreach (range(1, 100) as $number) {
            $business->services()->create(['category_id' => $category->id, 'name' => 'Service '.$number, 'slug' => 'service-'.$index.'-'.$number, 'status' => 'active']);
        }
    }
    $response = $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home')->assertOk()
        ->assertJsonCount(2, 'data.categories.0.businesses');
    foreach ([0, 1] as $index) {
        $response->assertJsonCount(100, 'data.categories.0.businesses.'.$index.'.services')
            ->assertJsonPath('data.categories.0.businesses.'.$index.'.services_count', 101)
            ->assertJsonPath('data.categories.0.businesses.'.$index.'.services_has_more', true);
    }
    $nextPage = $this->getJson('/api/v1/home?service_page=2')->assertOk();
    foreach ([0, 1] as $index) {
        $nextPage->assertJsonCount(1, 'data.categories.0.businesses.'.$index.'.services')
            ->assertJsonPath('data.categories.0.businesses.'.$index.'.services_has_more', false);
    }
});

it('validates catalog filters before querying', function (array $filters, string $field): void {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home?'.http_build_query($filters))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'coordinate pair' => [['latitude' => 27.7], 'longitude'],
    'invalid coordinate' => [['latitude' => 91, 'longitude' => 85.3], 'latitude'],
    'invalid radius' => [['latitude' => 27.7, 'longitude' => 85.3, 'radius_km' => 0], 'radius_km'],
    'invalid service flag' => [['home_service' => 'invalid'], 'home_service'],
    'invalid business page' => [['business_page' => 0], 'business_page'],
    'oversized business page' => [['businesses_per_page' => 101], 'businesses_per_page'],
    'invalid location text' => [['location' => ['Kathmandu']], 'location'],
    'invalid sorting' => [['sort' => 'invalid'], 'sort'],
    'invalid business id' => [['business_id' => 'invalid'], 'business_id'],
]);

it('filters the catalog by business id alongside search and eligibility filters', function (string $endpoint, string $businessPath, string $collectionPath): void {
    $category = Category::query()->create(['name' => 'Wellness Therapy', 'slug' => 'wellness-therapy', 'is_active' => true]);
    [$business] = catalogBusiness($category);
    catalogBusiness($category);
    $this->actingAs(User::factory()->create(), 'sanctum');
    $query = http_build_query(['business_id' => $business->id, 'q' => 'therapy', 'category_id' => $category->id]);

    $this->getJson($endpoint.'?'.$query)->assertOk()
        ->assertJsonCount(1, $businessPath)->assertJsonPath($businessPath.'.0.id', $business->id);
    $this->getJson($endpoint.'?'.$query.'&city=Missing')->assertOk()->assertJsonCount(0, $collectionPath);

    $business->update(['is_online' => false]);

    $this->getJson($endpoint.'?'.$query)->assertOk()->assertJsonCount(0, $collectionPath);
})->with([
    'home' => ['/api/v1/home', 'data.categories.0.businesses', 'data.categories'],
    'new home' => ['/api/v1/newHome', 'data.categories.0.businesses', 'data.categories'],
    'businesses' => ['/api/v1/businesses', 'data.items', 'data.items'],
]);

it('shares service search and boolean filters between home and business listings', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$business, $service] = catalogBusiness($category);
    $business->update(['home_service' => false]);
    $service->update(['name' => 'Himalayan Ritual']);
    [$other, $otherService] = catalogBusiness($category);
    $other->update(['home_service' => true]);
    $otherService->update(['name' => 'Himalayan Ritual']);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home?q=ritual&home_service=false')
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.categories.0.businesses.0.id', $business->id);
    $this->getJson('/api/v1/businesses?q=ritual&home_service=false')->assertOk()
        ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $business->id);
});

it('lists a business under each category with only that category services', function (): void {
    $massage = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true, 'sort_order' => 1]);
    $facial = Category::query()->create(['name' => 'Facial', 'slug' => 'facial', 'is_active' => true, 'sort_order' => 2]);
    [$business, $service] = catalogBusiness($massage);
    $facialService = $business->services()->create(['category_id' => $facial->id, 'name' => 'Facial Treatment', 'slug' => 'facial-treatment', 'status' => 'active']);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home')->assertOk()
        ->assertJsonCount(2, 'data.categories')
        ->assertJsonPath('data.categories.0.businesses.0.id', $business->id)
        ->assertJsonCount(1, 'data.categories.0.businesses.0.services')
        ->assertJsonPath('data.categories.0.businesses.0.services.0.id', $service->id)
        ->assertJsonPath('data.categories.1.businesses.0.id', $business->id)
        ->assertJsonCount(1, 'data.categories.1.businesses.0.services')
        ->assertJsonPath('data.categories.1.businesses.0.services.0.id', $facialService->id);
});

it('searches category names with q while retaining business filters', function (string $endpoint, string $businessPath): void {
    $category = Category::query()->create(['name' => 'Wellness Therapy', 'slug' => 'wellness-therapy', 'is_active' => true]);
    [$business] = catalogBusiness($category);
    catalogBusiness($category, 'Pokhara');
    [$offline] = catalogBusiness($category);
    $offline->update(['is_online' => false]);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson($endpoint.'?'.http_build_query(['q' => ' ThErAp ', 'city' => 'Kathmandu']))
        ->assertOk()->assertJsonCount(1, $businessPath)
        ->assertJsonPath($businessPath.'.0.id', $business->id);
})->with([
    'home' => ['/api/v1/home', 'data.categories.0.businesses'],
    'new home' => ['/api/v1/newHome', 'data.categories.0.businesses'],
    'businesses' => ['/api/v1/businesses', 'data.items'],
]);

it('restricts category name search to the current category and active services', function (string $endpoint): void {
    $matched = Category::query()->create(['name' => 'Wellness Therapy', 'slug' => 'wellness-therapy', 'is_active' => true]);
    $other = Category::query()->create(['name' => 'Skincare', 'slug' => 'skincare', 'is_active' => true]);
    [$business] = catalogBusiness($matched);
    $business->services()->create(['category_id' => $other->id, 'name' => 'Basic Treatment', 'slug' => 'basic-treatment', 'status' => 'active']);
    $business->services()->create(['name' => 'General Treatment', 'slug' => 'general-treatment', 'status' => 'active']);
    [$paused, $pausedService] = catalogBusiness($matched);
    $pausedService->update(['status' => 'paused']);
    [$hidden, $hiddenService] = catalogBusiness($other);
    $inactive = Category::query()->create(['name' => 'Hidden Therapy', 'slug' => 'hidden-therapy', 'is_active' => false]);
    $hiddenService->update(['category_id' => $inactive->id]);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson($endpoint.'?q=therapy')->assertOk()
        ->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.id', $matched->id)
        ->assertJsonCount(1, 'data.categories.0.businesses')
        ->assertJsonPath('data.categories.0.businesses.0.id', $business->id);
    $this->getJson($endpoint.'?q=therapy&category_id='.$other->id)->assertOk()
        ->assertJsonCount(0, 'data.categories');
    $this->getJson('/api/v1/businesses?q=therapy&category_id='.$other->id)->assertOk()
        ->assertJsonCount(0, 'data.items');
})->with([
    'home' => ['/api/v1/home'],
    'new home' => ['/api/v1/newHome'],
]);

it('pages businesses independently in every category with category specific services and totals', function (): void {
    $massage = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true, 'sort_order' => 1]);
    $facial = Category::query()->create(['name' => 'Facial', 'slug' => 'facial', 'is_active' => true, 'sort_order' => 2]);
    [$first] = catalogBusiness($massage);
    [$second] = catalogBusiness($massage);
    $first->update(['name' => 'Alpha Spa']);
    $second->update(['name' => 'Beta Spa']);
    foreach ([$first, $second] as $business) {
        $business->services()->create(['category_id' => $facial->id, 'name' => 'Facial Treatment', 'slug' => 'facial-'.$business->id, 'status' => 'active']);
    }
    $first->services()->create(['category_id' => $massage->id, 'name' => 'Another Massage', 'slug' => 'another-massage', 'status' => 'active']);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $response = $this->getJson('/api/v1/home?businesses_per_page=1&services_per_page=1&sort=name')->assertOk()
        ->assertJsonPath('data.pagination.total', 2)->assertJsonCount(2, 'data.categories');
    foreach ([0, 1] as $index) {
        $response->assertJsonCount(1, 'data.categories.'.$index.'.businesses')
            ->assertJsonPath('data.categories.'.$index.'.businesses.0.id', $first->id)
            ->assertJsonCount(1, 'data.categories.'.$index.'.businesses.0.services')
            ->assertJsonPath('data.categories.'.$index.'.pagination.total', 2)
            ->assertJsonPath('data.categories.'.$index.'.pagination.has_more', true);
    }
    $response->assertJsonPath('data.categories.0.businesses.0.services_count', 2)
        ->assertJsonPath('data.categories.1.businesses.0.services_count', 1)
        ->assertJsonPath('data.categories.1.businesses.0.services.0.name', 'Facial Treatment');
    $this->getJson('/api/v1/home?businesses_per_page=1&services_per_page=1&service_page=2&sort=name')->assertOk()
        ->assertJsonCount(1, 'data.categories.0.businesses.0.services')
        ->assertJsonPath('data.categories.0.businesses.0.services_pagination.total', 2)
        ->assertJsonCount(0, 'data.categories.1.businesses.0.services')
        ->assertJsonPath('data.categories.1.businesses.0.services_pagination.total', 1);
    $nextPage = $this->getJson('/api/v1/home?businesses_per_page=1&business_page=2&sort=name')->assertOk();
    foreach ([0, 1] as $index) {
        $nextPage->assertJsonPath('data.categories.'.$index.'.businesses.0.id', $second->id)
            ->assertJsonPath('data.categories.'.$index.'.pagination.has_more', false);
    }
    $this->getJson('/api/v1/home?per_page=1&page=2')->assertOk()
        ->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.id', $facial->id);
    $this->getJson('/api/v1/home?business_page=3&businesses_per_page=1')->assertOk()
        ->assertJsonCount(2, 'data.categories')->assertJsonCount(0, 'data.categories.0.businesses')
        ->assertJsonPath('data.categories.0.pagination.total', 2);
});

it('does not match services outside the category or hidden services during catalog search', function (): void {
    $massage = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    $facial = Category::query()->create(['name' => 'Facial', 'slug' => 'facial', 'is_active' => true]);
    [$business] = catalogBusiness($massage);
    $business->services()->create(['category_id' => $facial->id, 'name' => 'Unique Ritual', 'slug' => 'unique-ritual', 'status' => 'active']);
    $business->services()->create(['category_id' => $massage->id, 'name' => 'Hidden Ritual', 'slug' => 'hidden-ritual', 'status' => 'paused']);
    $business->services()->create(['name' => 'Uncategorized Treatment', 'slug' => 'uncategorized-treatment', 'status' => 'active']);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/home?q=unique')->assertOk()->assertJsonCount(1, 'data.categories')
        ->assertJsonPath('data.categories.0.id', $facial->id);
    $this->getJson('/api/v1/home?q=unique&category_id='.$massage->id)->assertOk()
        ->assertJsonPath('data.pagination.total', 0)->assertJsonCount(0, 'data.categories');
    $this->getJson('/api/v1/businesses?q=unique&category_id='.$massage->id)->assertOk()
        ->assertJsonPath('data.pagination.total', 0);
    $this->getJson('/api/v1/home?q=hidden')->assertOk()->assertJsonCount(0, 'data.categories');
});

it('excludes categories linked to a business without active services in that category', function (): void {
    $empty = Category::query()->create(['name' => 'Empty', 'slug' => 'empty', 'is_active' => true]);
    $visible = Category::query()->create(['name' => 'Visible', 'slug' => 'visible', 'is_active' => true]);
    [$business] = catalogBusiness($visible);
    $business->categories()->attach($empty);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home')->assertOk()
        ->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.id', $visible->id);
});

it('orders nearby businesses within categories and preserves distance metadata', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$nearest] = catalogBusiness($category);
    [$further] = catalogBusiness($category);
    $further->locations()->first()->update(['latitude' => 27.72]);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home?latitude=27.7&longitude=85.3&businesses_per_page=1')
        ->assertOk()->assertJsonPath('data.categories.0.businesses.0.id', $nearest->id)
        ->assertJsonPath('data.categories.0.businesses.0.distance_km', 0)
        ->assertJsonPath('data.categories.0.pagination.total', 2);
});

it('loads only verified online businesses with active services through categoriesWithBusiness', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$visible, $service] = catalogBusiness($category);
    foreach ([['is_verified' => false], ['is_online' => false], ['status' => 'paused']] as $attributes) {
        [$excluded] = catalogBusiness($category);
        $excluded->update($attributes);
    }
    [$pausedBusiness, $pausedService] = catalogBusiness($category);
    $pausedService->update(['status' => 'paused']);

    $categories = app(BusinessService::class)->categoriesWithBusiness();

    expect($categories->modelKeys())->toBe([$category->id]);
    expect($categories->first()->businesses->modelKeys())->toBe([$visible->id]);
    expect($categories->first()->businesses->first()->services->modelKeys())->toBe([$service->id]);
});

it('applies location filters when loading categoriesWithBusiness', function (array $filters): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$matched] = catalogBusiness($category, 'Kathmandu');
    $matched->locations()->first()->update(['address' => 'Lotus Lane', 'latitude' => 27.7]);
    [$excluded] = catalogBusiness($category, 'Pokhara');
    $excluded->locations()->first()->update(['address' => 'Lake Road', 'latitude' => 28.2, 'longitude' => 83.9]);

    $categories = app(BusinessService::class)->categoriesWithBusiness($filters);

    expect($categories->first()->businesses->modelKeys())->toBe([$matched->id]);
})->with([
    'city' => [['city' => ' kathMANDu ']],
    'address' => [['address' => ' lotus ']],
    'location city' => [['location' => ' kathMANDu ']],
    'location address' => [['location' => ' lotus ']],
    'nearby' => [['latitude' => 27.7, 'longitude' => 85.3, 'radius_km' => 3]],
]);

it('shares address or city location search between home and the flat business catalog', function (string $location): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$matched] = catalogBusiness($category, 'Kathmandu');
    $matched->locations()->first()->update(['address' => 'Lotus Lane']);
    catalogBusiness($category, 'Pokhara');
    $this->actingAs(User::factory()->create(), 'sanctum');
    $query = http_build_query(['location' => $location]);

    $this->getJson('/api/v1/home?'.$query)->assertOk()
        ->assertJsonCount(1, 'data.categories.0.businesses')
        ->assertJsonPath('data.categories.0.businesses.0.id', $matched->id)
        ->assertJsonPath('data.categories.0.pagination.total', 1);
    $this->getJson('/api/v1/businesses?'.$query)->assertOk()
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $matched->id);
})->with([' kathMANDu ', ' lotus ']);

it('requires location text and proximity filters to match the same business location', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$business] = catalogBusiness($category, 'Kathmandu');
    $business->locations()->create(['address' => 'Lake Road', 'city' => 'Pokhara', 'latitude' => 28.2, 'longitude' => 83.9, 'service_availability' => 'on_site']);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/home?location=Pokhara&latitude=27.7&longitude=85.3&radius_km=3')
        ->assertOk()->assertJsonCount(0, 'data.categories');
    $this->getJson('/api/v1/home?location=Lake&city=Kathmandu')->assertOk()->assertJsonCount(0, 'data.categories');
});

it('excludes categories with no matching businesses and ignores pagination in categoriesWithBusiness', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    catalogBusiness($category);
    catalogBusiness($category);
    $service = app(BusinessService::class);

    $empty = $service->categoriesWithBusiness(['location' => 'Missing Location']);
    $beyondLastPage = $service->categoriesWithBusiness(['businesses_per_page' => 1, 'business_page' => 3]);

    expect($empty)->toBeEmpty();
    expect($beyondLastPage->modelKeys())->toBe([$category->id]);
    expect($beyondLastPage->first()->businesses)->toHaveCount(2);
    expect($beyondLastPage->first()->getAttributes())->not->toHaveKeys(['businesses_count', 'businesses_page', 'businesses_per_page']);
});

it('returns all businesses and category specific services without pagination limits', function (): void {
    $massage = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true, 'sort_order' => 1]);
    $facial = Category::query()->create(['name' => 'Facial', 'slug' => 'facial', 'is_active' => true, 'sort_order' => 2]);
    [$business] = catalogBusiness($massage);
    foreach (range(1, 100) as $index) {
        $business->services()->create(['category_id' => $massage->id, 'name' => 'Massage '.$index, 'slug' => 'massage-'.$index, 'status' => 'active']);
    }
    $facialService = $business->services()->create(['category_id' => $facial->id, 'name' => 'Facial Treatment', 'slug' => 'facial-treatment', 'status' => 'active']);
    foreach (range(1, 20) as $index) {
        catalogBusiness($massage);
    }

    $categories = app(BusinessService::class)->categoriesWithBusiness([
        'business_page' => 3, 'businesses_per_page' => 1, 'service_page' => 3, 'services_per_page' => 1,
    ]);

    expect($categories->first()->businesses)->toHaveCount(21);
    $massageBusiness = $categories->first()->businesses->firstWhere('id', $business->id);
    expect($massageBusiness->services)->toHaveCount(101);
    expect($massageBusiness->services->pluck('category_id')->unique()->all())->toBe([$massage->id]);
    expect($massageBusiness->getAttributes())->not->toHaveKeys(['services_count', 'services_page', 'services_per_page']);
    expect($categories->last()->businesses->first()->services->modelKeys())->toBe([$facialService->id]);
});

it('eager loads only locations matching the requested filters', function (array $filters): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$business] = catalogBusiness($category, 'Kathmandu');
    $matchingLocation = $business->locations()->create([
        'address' => 'Lake Road', 'city' => 'Pokhara', 'latitude' => 28.2, 'longitude' => 83.9,
        'service_availability' => 'on_site',
    ]);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/home?'.http_build_query($filters))->assertOk()
        ->assertJsonPath('data.categories.0.businesses.0.id', $business->id)
        ->assertJsonCount(1, 'data.categories.0.businesses.0.locations')
        ->assertJsonPath('data.categories.0.businesses.0.locations.0.id', $matchingLocation->id)
        ->assertJsonPath('data.categories.0.businesses.0.city', 'Pokhara');
    $categories = app(BusinessService::class)->categoriesWithBusiness($filters);
    expect($categories->first()->businesses->first()->relationLoaded('locations'))->toBeTrue();
    expect($categories->first()->businesses->first()->locations->modelKeys())->toBe([$matchingLocation->id]);
})->with([
    'location text' => [['location' => 'Pokhara']],
    'city and proximity' => [['city' => 'Pokhara', 'latitude' => 28.2, 'longitude' => 83.9, 'radius_km' => 3]],
]);

it('uses a fixed query count and eager loaded relations for an unpaginated filtered catalog', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    catalogBusiness($category);
    catalogBusiness($category);
    $service = app(BusinessService::class);
    $queryCounts = [];
    $preventedLazyLoading = Model::preventsLazyLoading();
    Model::preventLazyLoading();

    try {
        foreach ([2, 8] as $count) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $categories = $service->categoriesWithBusiness(['q' => 'Test', 'location' => 'Kathmandu']);
            expect($categories->first()->businesses)->toHaveCount($count);
            $categories->toArray();
            $queryCounts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            if ($count === 2) {
                foreach (range(1, 6) as $index) {
                    catalogBusiness($category);
                }
            }
        }
    } finally {
        DB::disableQueryLog();
        Model::preventLazyLoading($preventedLazyLoading);
    }

    expect($queryCounts[0])->toBe($queryCounts[1])->toBeLessThanOrEqual(8);
});

it('can skip service eager loading while retaining category and business eligibility', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$business] = catalogBusiness($category);
    [$paused, $pausedService] = catalogBusiness($category);
    $pausedService->update(['status' => 'paused']);

    $categories = app(BusinessService::class)->categoriesWithBusiness(['location' => 'Kathmandu'], loadServices: false);

    expect($categories->modelKeys())->toBe([$category->id]);
    expect($categories->first()->businesses->modelKeys())->toBe([$business->id]);
    expect($categories->first()->businesses->first()->relationLoaded('services'))->toBeFalse();
    expect($categories->first()->businesses->first()->relationLoaded('locations'))->toBeTrue();
});

it('returns the unpaginated new home catalog in the API envelope without unloaded services or counts', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$business] = catalogBusiness($category, 'Kathmandu');
    catalogBusiness($category, 'Pokhara');
    $user = User::factory()->create();
    $preventedLazyLoading = Model::preventsLazyLoading();
    Model::preventLazyLoading();

    try {
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/newHome?location=Kathmandu')->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('message', 'Home catalog loaded.')
            ->assertJsonStructure(['data' => ['user' => ['id', 'display_name', 'mobile_number', 'avatar_url', 'address', 'city', 'latitude', 'longitude'], 'unread_notification_count', 'banner', 'categories']])
            ->assertJsonPath('data.user.id', $user->id)->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.id', $category->id)->assertJsonCount(1, 'data.categories.0.businesses')
            ->assertJsonPath('data.categories.0.businesses.0.id', $business->id)
            ->assertJsonPath('data.categories.0.businesses.0.locations.0.city', 'Kathmandu');
    } finally {
        Model::preventLazyLoading($preventedLazyLoading);
    }

    expect($response->json('data'))->not->toHaveKey('pagination');
    expect($response->json('data.categories.0'))->not->toHaveKeys(['pagination', 'businesses_count']);
    expect($response->json('data.categories.0.businesses.0'))->not->toHaveKeys(['categories', 'services', 'services_count', 'services_pagination', 'pivot', 'kyc_documents', 'address', 'city', 'latitude', 'longitude']);
});

it('returns an empty unpaginated catalog for new home when no businesses match', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/newHome?location=Missing')
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.categories', []);
});

it('includes visible business reviews in home even when services are not loaded', function (): void {
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    [$business, $service] = catalogBusiness($category);
    $customer = User::factory()->create();
    $review = null;
    foreach ([false, true] as $hidden) {
        $booking = Booking::query()->create([
            'user_id' => $customer->id,
            'business_id' => $business->id,
            'appointment_date' => now()->toDateString(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'completed',
            'completed_at' => now(),
            'subtotal_minor' => 100000,
            'total_minor' => 100000,
            'currency' => 'NPR',
        ]);
        $created = $business->reviews()->create([
            'booking_id' => $booking->id, 'user_id' => $customer->id, 'service_id' => $service->id,
            'rating' => 5, 'comments' => 'Relaxing treatment', 'is_hidden' => $hidden,
        ]);
        if (! $hidden) {
            $review = $created;
        }
    }
    $this->actingAs($customer, 'sanctum');
    $preventedLazyLoading = Model::preventsLazyLoading();
    Model::preventLazyLoading();

    try {
        $this->getJson('/api/v1/home')->assertOk()
            ->assertJsonCount(1, 'data.categories.0.businesses.0.reviews')
            ->assertJsonPath('data.categories.0.businesses.0.reviews.0.id', $review->id)
            ->assertJsonPath('data.categories.0.businesses.0.reviews.0.rating', 5)
            ->assertJsonPath('data.categories.0.businesses.0.reviews.0.customer.id', $customer->id);
    } finally {
        Model::preventLazyLoading($preventedLazyLoading);
    }
});

it('requires authentication for new home', function (): void {
    $this->getJson('/api/v1/newHome')->assertUnauthorized();
});

it('serializes the catalog user summary with preloaded roles without database queries', function (): void {
    $user = User::factory()->create(['latitude' => 0, 'longitude' => null, 'image' => 'avatars/catalog.jpg']);
    $user->assignRole('customer');
    $user->load('roles');
    $catalog = new CatalogData($user, new Collection, 3);
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $data = $catalog->toArray();
        expect(DB::getQueryLog())->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }

    expect($data['user'])->toBe([
        'id' => $user->id,
        'display_name' => $user->display_name,
        'mobile_number' => $user->mobile,
        'roles' => ['customer'],
        'avatar_url' => url('storage/avatars/catalog.jpg'),
        'address' => $user->address,
        'city' => $user->city,
        'latitude' => 0.0,
        'longitude' => null,
    ]);
    expect($user->relationLoaded('roles'))->toBeTrue();
    expect($data)->toHaveKeys(['user', 'unread_notification_count', 'banner', 'categories'])->not->toHaveKeys(['location', 'pagination']);
    expect($data['unread_notification_count'])->toBe(3);
    expect($data['categories'])->toBe([]);
});
