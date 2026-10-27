<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessClosure;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessOnboarding;
use App\Models\Category;
use App\Models\Service;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BusinessService
{
    public function __construct(
        private readonly Category $category,
        private readonly BusinessRegistrationService $registration,
        private readonly BusinessLocationService $locations,
        private readonly BusinessCategoryService $categories,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(User $owner, array $data): Business
    {
        return $this->registration->register($owner, $data);
    }

    /** @return Collection<int, Business> */
    public function managedBy(User $user): Collection
    {
        return $user->businesses()
            ->with(['locations', 'categories'])
            ->withPivot('role', 'is_active')
            ->wherePivot('is_active', true)
            ->get();
    }

    public function profile(Business $business): Business
    {
        return $business->load(['locations', 'categories', 'hours', 'closures']);
    }

    public function documentPath(Business $business, string $document): ?string
    {
        $path = in_array($document, Business::KYC_DOCUMENT_TYPES, true) ? ($business->kyc_documents[$document] ?? null) : null;

        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }

    /** @param array<int, array{weekday: int, is_closed: bool, opens_at?: string|null, closes_at?: string|null}> $hours */
    public function updateHours(Business $business, array $hours): Business
    {
        DB::transaction(function () use ($hours, $business): void {
            foreach ($hours as $row) {
                BusinessHour::query()->updateOrCreate(
                    ['business_id' => $business->getKey(), 'weekday' => $row['weekday']],
                    [
                        'opens_at' => $row['is_closed'] ? null : $row['opens_at'] ?? null,
                        'closes_at' => $row['is_closed'] ? null : $row['closes_at'] ?? null,
                        'is_closed' => $row['is_closed'],
                    ],
                );
            }
        });

        return $business->refresh()->load('hours');
    }

    /**
     * @param  array<int, array{starts_on: string, ends_on?: string|null, reason?: string|null}>  $closures
     * @return Collection<int, BusinessClosure>
     */
    public function addClosures(Business $business, array $closures): Collection
    {
        return collect($closures)->map(fn (array $row) => BusinessClosure::query()->create($row + ['business_id' => $business->getKey()]));
    }

    public function deleteClosure(BusinessClosure $closure): bool
    {
        return (bool) $closure->delete();
    }

    /**
     * @param  array<string, mixed>  $filters  Validated search filters.
     * @return LengthAwarePaginator<int, Business>
     */
    public function paginate(int $perPage = 20, array $filters = [], bool $catalog = false): LengthAwarePaginator
    {
        $servicePage = max(1, (int) ($filters['service_page'] ?? 1));
        $servicesPerPage = min(100, max(1, (int) ($filters['services_per_page'] ?? 100)));
        $query = Business::query()->with('categories');
        $this->applyBusinessFilters($query, $filters, $catalog);

        if ($catalog) {
            if (isset($filters['category_id'])) {
                $query->whereHas('categories', fn (Builder $categories) => $categories->whereKey($filters['category_id'])->where('is_active', true));
                $query->whereHas('services', fn (Builder $services) => $this->catalogServices($services, $filters));
            }
            $query->withCount(['services as services_count' => fn (Builder $services) => $this->catalogServices($services, $filters)])
                ->with(['categories' => fn ($categories) => $categories->where('is_active', true)
                    ->when(isset($filters['category_id']), fn ($categories) => $categories->whereKey($filters['category_id']))
                    ->orderBy('sort_order')->orderBy('categories.id'), 'services' => fn ($services) => $this->catalogServices($services, $filters)
                    ->with(['category', 'currentPrices'])
                    ->orderBy('name')->orderBy('id')
                    ->offset(($servicePage - 1) * $servicesPerPage)->limit($servicesPerPage)]);

        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        $businesses = $query->paginate(min(100, max(1, $perPage)));
        if ($catalog) {
            $businesses->getCollection()->each(function (Business $business) use ($servicePage, $servicesPerPage): void {
                $business->setAttribute('services_page', $servicePage);
                $business->setAttribute('services_per_page', $servicesPerPage);
            });
        }

        return $businesses;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Category>
     */
    public function paginateCategories(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $servicePage = max(1, (int) ($filters['service_page'] ?? 1));
        $servicesPerPage = min(100, max(1, (int) ($filters['services_per_page'] ?? 100)));
        $eligibleBusinesses = fn (Builder $businesses) => $this->applyBusinessFilters($businesses, $filters, true, 'categories.id', false);
        $rows = Category::query()->select(['id', 'name', 'slug', 'sort_order', 'is_active'])
            ->where('is_active', true)
            ->when(isset($filters['category_id']), fn (Builder $categories) => $categories->whereKey($filters['category_id']))
            ->whereHas('businesses', $eligibleBusinesses);

        if (! isset($filters['category_id'])) {
            $uncategorized = Service::query()
                ->selectRaw("NULL as id, 'Uncategorized' as name, 'uncategorized' as slug, 0 as sort_order, 1 as is_active")
                ->whereNull('category_id')->where('status', 'active')
                ->whereHas('business', fn (Builder $businesses) => $this->applyBusinessFilters($businesses, [...$filters, 'uncategorized_only' => true], true, null, false))
                ->limit(1);
            $rows->unionAll($uncategorized);
        }

        $categories = Category::query()->fromSub($rows, 'categories')->select('categories.*')
            ->withCount(['businesses' => $eligibleBusinesses])
            ->orderBy('sort_order')->orderBy('id')
            ->paginate(min(100, max(1, $perPage)));
        $this->loadCategoryBusinesses($categories->getCollection(), $filters);

        $this->loadCategoryServices($categories->getCollection(), $servicePage, $servicesPerPage);

        return $categories;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return EloquentCollection<int, Category>
     */
    public function categoriesWithBusiness(array $filters = [], bool $loadServices = true): EloquentCollection
    {
        $filters['verified_only'] = true;
        $categories = $this->category->newQuery()->select('id', 'name', 'slug')->where('is_active', true)
            ->when(isset($filters['category_id']), fn (Builder $categories) => $categories->whereKey($filters['category_id']))
            ->withWhereHas('businesses', function (Builder|Relation $query) use ($filters): void {
                $businesses = $query instanceof Relation ? $query->getQuery() : $query;
                if ($query instanceof Relation) {
                    $businesses->select(['businesses.id', 'businesses.name', 'businesses.slug', 'businesses.about', 'businesses.hero_image_path', 'businesses.phone_number'])
                        ->with(['reviews' => fn (Relation $reviews) => $reviews->where('is_hidden', false)->with('user.roles')->latest()]);
                }
                $this->applyBusinessFilters(
                    $businesses, $filters, true, 'business_category.category_id',
                    withDistance: $query instanceof Relation,
                );
            })
            ->orderBy('sort_order')->orderBy('id')->get();

        if ($loadServices) {
            $categories->map(function (Category $category): Category {
                $category->businesses->load(['services' => fn (Relation $services) => $services
                    ->where('status', 'active')->where('category_id', $category->id)
                    ->with('currentPrices')->orderBy('name')->orderBy('id')]);

                return $category;
            });
        }

        return $categories;
    }

    /**
     * @param  EloquentCollection<int, Category>  $categories
     * @param  array<string, mixed>  $filters
     */
    private function loadCategoryBusinesses(EloquentCollection $categories, array $filters): void
    {
        $businessPage = max(1, (int) ($filters['business_page'] ?? 1));
        $businessesPerPage = min(100, max(1, (int) ($filters['businesses_per_page'] ?? 20)));
        $categories->load([
            'businesses' => function (Relation $businesses) use ($filters, $businessPage, $businessesPerPage): void {
                $this->applyBusinessFilters($businesses->getQuery(), $filters, true, 'business_category.category_id');
                $businesses->addSelect('businesses.*')
                    ->withCount(['services' => fn (Builder $services) => $services->where('status', 'active')
                        ->whereColumn('services.category_id', 'business_category.category_id')])
                    ->offset(($businessPage - 1) * $businessesPerPage)->limit($businessesPerPage);
            },
        ]);

        foreach ($categories as $category) {
            if ($category->id === null) {
                $businesses = Business::query();
                $this->applyBusinessFilters($businesses, [...$filters, 'uncategorized_only' => true], true);
                $page = $businesses->withCount(['services' => fn (Builder $services) => $services->where('status', 'active')->whereNull('category_id')])
                    ->paginate($businessesPerPage, ['businesses.*'], 'business_page', $businessPage);
                $category->setRelation('businesses', $page->getCollection());
                $category->setAttribute('businesses_count', $page->total());
            }
            $category->setAttribute('businesses_page', $businessPage);
            $category->setAttribute('businesses_per_page', $businessesPerPage);
        }
    }

    /** @param EloquentCollection<int, Category> $categories */
    private function loadCategoryServices(EloquentCollection $categories, int $servicePage, int $servicesPerPage): void
    {
        $businessIds = $categories->flatMap(fn (Category $category) => $category->businesses->modelKeys())->unique()->values();
        $services = new EloquentCollection;
        if ($businessIds->isNotEmpty()) {
            $query = Service::query()->where('status', 'active')->whereIn('business_id', $businessIds)
                ->where(function (Builder $services) use ($categories): void {
                    foreach ($categories as $category) {
                        $services->orWhere(function (Builder $services) use ($category): void {
                            $services->where('category_id', $category->id)->whereIn('business_id', $category->businesses->modelKeys());
                        });
                    }
                })
                ->with('currentPrices')->orderBy('name')->orderBy('id')
                ->offset(($servicePage - 1) * $servicesPerPage);
            $query->getQuery()->groupLimit($servicesPerPage, DB::raw('services.business_id, services.category_id'));
            $services = $query->get();
        }
        $groups = $services->groupBy(fn (Service $service): string => $service->business_id.':'.($service->category_id ?? 'uncategorized'));
        foreach ($categories as $category) {
            foreach ($category->businesses as $business) {
                $business->setRelation('categories', new EloquentCollection([$category]));
                $business->setRelation('services', new EloquentCollection($groups->get($business->id.':'.($category->id ?? 'uncategorized'), collect())->all()));
                $business->setAttribute('services_page', $servicePage);
                $business->setAttribute('services_per_page', $servicesPerPage);
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function orderCatalogBusinesses(Builder $query, array $filters): void
    {
        match ($filters['sort'] ?? 'rating') {
            'name' => $query->orderBy('businesses.name'),
            'newest' => $query->orderByDesc('businesses.created_at'),
            default => $query->orderByDesc('rating_average')->orderBy('businesses.name'),
        };
    }

    /** @param array<string, mixed> $filters */
    private function applyBusinessFilters(Builder $query, array $filters, bool $catalog = false, ?string $categoryColumn = null, bool $withDistance = true): void
    {
        if (isset($filters['business_id'])) {
            $query->where('businesses.id', $filters['business_id']);
        }

        if ($withDistance) {
            $query->with('locations');
        }
        if ($catalog) {
            $query->where('businesses.status', 'active')->where('businesses.is_online', true)
                ->when($filters['verified_only'] ?? false, fn (Builder $query) => $query->where('businesses.is_verified', true));
            if ($categoryColumn !== null || ($filters['uncategorized_only'] ?? false)) {
                $query->whereHas('services', fn (Builder $services) => $services->where('status', 'active')
                    ->when($categoryColumn !== null,
                        fn (Builder $services) => $services->whereColumn('services.category_id', $categoryColumn),
                        fn (Builder $services) => $services->whereNull('category_id'),
                    ));
            }
        }

        $search = trim($filters['q'] ?? '');

        if ($search !== '') {
            $like = '%'.Str::lower($search).'%';
            $query->where(function (Builder $query) use ($like, $filters, $catalog, $categoryColumn): void {
                $query->whereLike('businesses.name', $like)
                    ->orWhereLike('businesses.slug', $like)
                    ->orWhereLike('businesses.about', $like)
                    ->orWhereHas('services', function (Builder $services) use ($like, $filters, $catalog, $categoryColumn): void {
                        if ($categoryColumn !== null) {
                            $services->where('status', 'active')->whereColumn('services.category_id', $categoryColumn);
                        } elseif ($catalog) {
                            $this->catalogServices($services, $filters);
                        } else {
                            $services->where('status', 'active');
                        }
                        $services->where(function (Builder $services) use ($like, $catalog): void {
                            $services->whereLike('name', $like)->orWhereLike('search_name', $like)
                                ->orWhereHas('category', fn (Builder $category) => $category
                                    ->when($catalog, fn (Builder $category) => $category->where('is_active', true))
                                    ->whereLike('name', $like));
                        });
                    })
                    ->orWhereHas('locations', fn (Builder $locations) => $locations
                        ->whereLike('address', $like)
                        ->orWhereLike('city', $like));
            });
        }

        foreach (['name', 'slug'] as $field) {
            $value = trim($filters[$field] ?? '');
            if ($value !== '') {
                $query->whereLike('businesses.'.$field, '%'.$value.'%');
            }
        }

        if (! isset($filters['latitude'], $filters['longitude'])
            && (trim($filters['address'] ?? '') !== '' || trim($filters['city'] ?? '') !== '' || trim($filters['location'] ?? '') !== '')) {
            $locationMethod = $withDistance ? 'withWhereHas' : 'whereHas';
            $query->{$locationMethod}('locations', function (Builder|Relation $locations) use ($filters): void {
                $this->applyLocationTextFilters($locations instanceof Relation ? $locations->getQuery() : $locations, $filters);
            });
        }

        foreach (['home_service', 'location_service'] as $field) {
            if (isset($filters[$field])) {
                $query->where('businesses.'.$field, $filters[$field]);
            }
        }

        $this->applyNearbyFilter($query, $filters, $withDistance);
        if ($catalog && $withDistance) {
            $this->orderCatalogBusinesses($query, $filters);
            $query->orderByDesc('businesses.id');
        }
    }

    /** @param array<string, mixed> $filters */
    private function catalogServices(Builder|Relation $services, array $filters): Builder|Relation
    {
        $services->where('status', 'active')
            ->when(isset($filters['category_id']), fn (Builder|Relation $services) => $services->where('category_id', $filters['category_id']));

        if ($filters['uncategorized_only'] ?? false) {
            return $services->whereNull('category_id');
        }

        $services->where(function (Builder $services): void {
            $services->whereNull('category_id')->orWhereHas('category', fn (Builder $category) => $category->where('is_active', true)
                ->whereHas('businesses', fn (Builder $businesses) => $businesses->whereColumn('businesses.id', 'services.business_id')));
        });

        return $services;
    }

    /** @param array<string, mixed> $filters */
    private function applyLocationTextFilters(Builder $locations, array $filters): void
    {
        $location = trim($filters['location'] ?? '');
        if ($location !== '') {
            $locations->where(function (Builder $locations) use ($location): void {
                $locations->whereLike('address', '%'.$location.'%')->orWhereLike('city', '%'.$location.'%');
            });
        }

        foreach (['address', 'city'] as $field) {
            $value = trim($filters[$field] ?? '');
            if ($value !== '') {
                $locations->whereLike($field, '%'.$value.'%');
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyNearbyFilter(Builder $query, array $filters, bool $withDistance = true): void
    {
        if (! isset($filters['latitude']) && ! isset($filters['longitude']) && ! isset($filters['radius_km'])) {
            return;
        }

        $location = Validator::make(Arr::only($filters, ['latitude', 'longitude', 'radius_km']), [
            'latitude' => ['nullable', 'required_with:longitude,radius_km', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude,radius_km', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'gt:0'],
        ])->validate();

        if (! isset($location['latitude'], $location['longitude'])) {
            return;
        }

        $distance = <<<'SQL'
            (6371.0088 * 2 * ASIN(SQRT(LEAST(1, GREATEST(0,
                POWER(SIN(RADIANS(latitude - ?) / 2), 2)
                + COS(RADIANS(?)) * COS(RADIANS(latitude))
                * POWER(SIN(RADIANS(longitude - ?) / 2), 2)
            )))))
            SQL;
        $bindings = [(float) $location['latitude'], (float) $location['latitude'], (float) $location['longitude']];
        $radius = (float) ($location['radius_km'] ?? 10);

        $nearest = BusinessLocation::query()
            ->selectRaw('MIN('.$distance.')', $bindings)
            ->whereColumn('business_locations.business_id', 'businesses.id')
            ->whereBetween('latitude', [-90, 90])
            ->whereBetween('longitude', [-180, 180]);

        $this->applyLocationTextFilters($nearest, $filters);

        if ($withDistance) {
            $query->select('businesses.*')->selectSub($nearest, 'distance_km')
                ->withCasts(['distance_km' => 'float'])->orderBy($nearest);
        }

        $locationMethod = $withDistance ? 'withWhereHas' : 'whereHas';
        $query->{$locationMethod}('locations', function (Builder|Relation $locations) use ($distance, $bindings, $radius, $filters): void {
            $locations = $locations instanceof Relation ? $locations->getQuery() : $locations;
            $this->applyLocationTextFilters($locations, $filters);
            $locations->whereBetween('latitude', [-90, 90])
                ->whereBetween('longitude', [-180, 180])
                ->whereRaw($distance.' <= ?', [...$bindings, $radius]);
        });
    }

    public function find(string $id): Business
    {
        return Business::query()->with(['locations', 'categories'])->findOrFail($id);
    }

    /** @param array<string, mixed> $data Validated business attributes. */
    public function create(array $data): Business
    {
        $business = new Business;
        $business->forceFill(['id' => (string) Str::ulid()]);
        $business->fill(Arr::except($data, ['category_ids', 'hero_image', 'locations', 'address', 'city', 'latitude', 'longitude']));
        $newFiles = [];

        try {
            if (isset($data['hero_image'])) {
                $business->hero_image_path = $this->storeUpload($data['hero_image'], 'business-images/'.$business->id, 'public', $newFiles);
            }

            if (empty($business->slug)) {
                $business->slug = Str::slug($data['name']).'-'.Str::lower($business->id);
            }

            return DB::transaction(function () use ($business, $data): Business {
                $business->save();
                $this->locations->save($business, $data);
                $this->categories->save($business, $data);

                return $business->refresh()->load(['locations', 'categories']);
            });
        } catch (Throwable $exception) {
            foreach ($newFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data Validated business attributes. */
    public function update(Business $business, array $data): Business
    {
        $newFiles = [];
        $oldFiles = [];

        try {
            $updated = DB::transaction(function () use ($business, $data, &$newFiles, &$oldFiles): Business {
                $locked = Business::query()->lockForUpdate()->findOrFail($business->getKey());
                $kycFields = array_intersect(array_keys($data), [...Business::KYC_DOCUMENT_TYPES, 'kyc_documents']);
                if (BusinessOnboarding::where('business_id', $locked->id)->exists() && array_intersect(array_keys($data), [...Business::KYC_DOCUMENT_TYPES, 'kyc_documents', 'name', 'phone_number']) !== []) {
                    throw ApiException::conflict(ErrorCode::Conflict, 'Use the onboarding correction flow to change application details or documents.');
                }

                if ($locked->is_verified && $kycFields !== []) {
                    throw ValidationException::withMessages(array_fill_keys($kycFields, 'KYC documents cannot be updated after business verification.'));
                }

                $documents = $data['kyc_documents'] ?? [];
                unset($data['kyc_documents']);
                foreach (Business::KYC_DOCUMENT_TYPES as $type) {
                    if (isset($data[$type])) {
                        $documents[$type] = $data[$type];
                    }
                    unset($data[$type]);
                }

                if (isset($data['hero_image'])) {
                    $data['hero_image_path'] = $this->storeUpload($data['hero_image'], 'business-images/'.$locked->id, 'public', $newFiles);
                }
                unset($data['hero_image']);

                if (array_key_exists('hero_image_path', $data) && $data['hero_image_path'] !== $locked->hero_image_path) {
                    $this->queueOldFile($locked->hero_image_path, 'business-images/'.$locked->id.'/', 'public', $oldFiles);
                }

                $paths = $locked->kyc_documents ?? [];
                foreach ($documents as $type => $file) {
                    if (! in_array($type, Business::KYC_DOCUMENT_TYPES, true) || ! $file instanceof UploadedFile) {
                        throw ValidationException::withMessages(['kyc_documents' => 'Provide uploaded files for supported KYC document types.']);
                    }
                    $paths[$type] = $this->storeUpload($file, 'business-kyc/'.$locked->id, 'local', $newFiles);
                    $this->queueOldFile($locked->kyc_documents[$type] ?? null, 'business-kyc/'.$locked->id.'/', 'local', $oldFiles);
                }

                $locked->fill(Arr::except($data, ['category_ids', 'locations', 'address', 'city', 'latitude', 'longitude']));
                if ($documents !== []) {
                    $locked->forceFill(['kyc_documents' => $paths]);
                }
                $locked->save();
                $this->locations->save($locked, $data);
                $this->categories->save($locked, $data);

                return $locked->refresh()->load(['locations', 'categories']);
            });
        } catch (Throwable $exception) {
            foreach ($newFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        foreach ($oldFiles as [$disk, $path]) {
            if (! in_array([$disk, $path], $newFiles, true)) {
                Storage::disk($disk)->delete($path);
            }
        }

        return $updated;
    }

    /** @param array<int, array{string, string}> $newFiles */
    private function storeUpload(UploadedFile $file, string $directory, string $disk, array &$newFiles): string
    {
        $path = $file->store($directory, $disk);

        if ($path === false) {
            throw new RuntimeException('Unable to store business upload.');
        }

        $newFiles[] = [$disk, $path];

        return $path;
    }

    /** @param array<int, array{string, string}> $oldFiles */
    private function queueOldFile(?string $path, string $prefix, string $disk, array &$oldFiles): void
    {
        if ($path !== null && Str::startsWith($path, $prefix) && basename($path) === substr($path, strlen($prefix)) && ! str_contains($path, '\\')) {
            $oldFiles[] = [$disk, $path];
        }
    }

    public function delete(Business $business): bool
    {
        return (bool) $business->delete();
    }
}
