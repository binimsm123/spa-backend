<?php

namespace App\Services;

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BusinessRegistrationService
{
    /** @param array<string, mixed> $data */
    public function register(User $owner, array $data): Business
    {
        $businessId = (string) Str::ulid();
        $paths = [];

        try {
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
                $business = new Business;
                $business->fill($data);
                $business->forceFill([
                    'id' => $businessId,
                    'slug' => Str::slug($data['name']).'-'.strtolower($businessId),
                    'kyc_documents' => $paths,
                    'status' => 'pending',
                    'is_verified' => false,
                    'is_online' => false,
                ])->save();

                $business->members()->attach($owner->getKey(), ['role' => BusinessUserRole::Owner->value, 'is_active' => true]);
                $owner->assignRole(BusinessUserRole::Owner->value);

                return $business->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($paths));

            throw $exception;
        }
    }
}
