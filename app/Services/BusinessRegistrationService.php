<?php

namespace App\Services;

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BusinessRegistrationService
{
    public function __construct(
        private readonly BusinessLocationService $locations,
        private readonly BusinessCategoryService $categories,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(User $owner, array $data): Business
    {
        $businessId = (string) Str::ulid();
        $paths = [];
        $heroImagePath = null;

        try {
            if (isset($data['hero_image'])) {
                /** @var UploadedFile $image */
                $image = $data['hero_image'];
                $path = $image->store('business-images/'.$businessId, 'public');

                if ($path === false) {
                    throw new RuntimeException('Unable to store business image.');
                }

                $heroImagePath = $path;
                $data['hero_image_path'] = $path;
            }
            unset($data['hero_image']);

            foreach (Business::KYC_DOCUMENT_TYPES as $type) {
                /** @var UploadedFile $file */
                $file = $data[$type];
                $path = $file->store('business-kyc/'.$businessId, 'local');

                if ($path === false) {
                    throw new RuntimeException('Unable to store business verification document.');
                }

                $paths[$type] = $path;
                unset($data[$type]);
            }

            return DB::transaction(function () use ($owner, $data, $businessId, $paths): Business {
                if (isset($data['locations']) && is_array($data['locations'])) {
                    $data = array_replace($data, $this->locations->serviceFlags($data['locations']));
                }

                $business = new Business;
                $business->fill(Arr::except($data, ['category_ids', 'locations', 'address', 'city', 'latitude', 'longitude']));
                $business->forceFill([
                    'id' => $businessId,
                    'slug' => Str::slug($data['name']).'-'.strtolower($businessId),
                    'kyc_documents' => $paths,
                    'status' => 'pending',
                    'is_verified' => false,
                    'is_online' => false,
                ])->save();

                $this->locations->save($business, $data);
                $this->categories->save($business, $data);

                $business->members()->attach($owner->getKey(), ['role' => BusinessUserRole::Owner->value, 'is_active' => true]);
                $owner->assignRole(BusinessUserRole::Owner->value);

                return $business->refresh()->load(isset($data['category_ids']) ? ['locations', 'categories'] : ['locations']);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($paths));

            if ($heroImagePath !== null) {
                Storage::disk('public')->delete($heroImagePath);
            }

            throw $exception;
        }
    }
}
