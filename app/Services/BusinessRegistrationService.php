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
        $personalPaths = [];
        $heroImagePath = null;
        $dob = Arr::pull($data, 'dob');
        $fullName = Arr::pull($data, 'full_name');

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

            foreach ($data['personal_kyc_document'] ?? [] as $type => $file) {
                /** @var UploadedFile $file */
                $path = $file->store('user-kyc/'.$owner->getKey(), 'local');

                if ($path === false) {
                    throw new RuntimeException('Unable to store personal verification document.');
                }

                $personalPaths[$type] = $path;
            }
            unset($data['personal_kyc_document']);

            return DB::transaction(function () use ($owner, $data, $businessId, $paths, $personalPaths, $dob, $fullName): Business {
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

                // Disable the legacy top-level address fallback for registration.
                // $this->locations->save($business, $data);
                $this->locations->save($business, Arr::only($data, ['locations']));
                $this->categories->save($business, $data);

                $business->members()->attach($owner->getKey(), ['role' => BusinessUserRole::Owner->value, 'is_active' => true]);
                $owner->assignRole(BusinessUserRole::Owner->value);

                $ownerData = [];
                if ($fullName !== null) {
                    $ownerData['display_name'] = $fullName;
                }
                if ($personalPaths !== []) {
                    $ownerData['kyc_document'] = $personalPaths;
                }
                if ($dob !== null) {
                    $ownerData['dob'] = $dob;
                }
                if ($ownerData !== []) {
                    $owner->forceFill($ownerData)->save();
                }

                return $business->refresh()->load(isset($data['category_ids']) ? ['locations', 'categories'] : ['locations']);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_merge(array_values($paths), array_values($personalPaths)));

            if ($heroImagePath !== null) {
                Storage::disk('public')->delete($heroImagePath);
            }

            throw $exception;
        }
    }
}
