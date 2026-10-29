<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessOnboarding;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BusinessOnboardingService
{
    public function ownerBusiness(User $user): ?Business
    {
        return $user->businesses()->wherePivot('role', 'owner')->wherePivot('is_active', true)
            ->orderBy('businesses.created_at')->orderBy('businesses.id')->first();
    }

    public function current(User $user): array
    {
        return $this->payload($user, $this->ownerBusiness($user));
    }

    private function payload(User $user, ?Business $business): array
    {
        $application = $business ? BusinessOnboarding::where('business_id', $business->id)->first() : null;

        return [
            'business' => $business ? [
                'id' => $business->id, 'name' => $business->name, 'phone_number' => $business->phone_number,
                'is_verified' => $business->is_verified, 'is_online' => $business->is_online, 'status' => $business->status,
                'outlet_number' => (int) $business->outlet_number,
                'kyc_status' => $application?->status ?? ($business->is_verified ? 'verified' : 'submitted'),
            ] : null,
            'application' => $application?->summary(),
            'personal_details' => [
                'full_name' => $user->display_name,
                'dob' => $user->dob?->format('Y-m-d'),
            ],
            'user_roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    /** @return array{int, array<string, mixed>} */
    public function submit(User $user, array $data, bool $resubmit): array
    {
        $key = Arr::pull($data, 'idempotency_key');
        $data['home_service_location'] ??= null;
        $hashData = $data;
        foreach ($hashData as $field => $value) {
            if ($value instanceof UploadedFile) {
                $hashData[$field] = ['sha256' => hash_file('sha256', $value->getRealPath()), 'name' => $value->getClientOriginalName(), 'size' => $value->getSize()];
            }
        }
        ksort($hashData);
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        $operation = $resubmit ? 'business.onboarding.resubmit' : 'business.onboarding.create';
        $newFiles = [];
        $oldFiles = [];

        try {
            $result = DB::transaction(function () use ($user, $data, $key, $hash, $operation, $resubmit, &$newFiles, &$oldFiles): array {
                // All mobile creation/review/resubmission paths serialize on the owner row.
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $existing = IdempotencyKey::where('user_id', $user->id)->where('operation', $operation)->where('key', $key)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw ApiException::conflict(ErrorCode::Conflict, 'This idempotency key was already used with a different onboarding request.');
                    }

                    return [$existing->response_code, $existing->response_json];
                }

                $business = $this->ownerBusiness($user);
                $application = $business ? BusinessOnboarding::where('business_id', $business->id)->lockForUpdate()->first() : null;
                if (! $resubmit && $business !== null) {
                    throw ApiException::conflict(ErrorCode::Conflict, 'A vendor profile already exists. Open its current application.');
                }
                if ($resubmit && ($application === null || $application->owner_user_id !== $user->id || $application->status !== 'rejected' || $business->is_verified)) {
                    throw ApiException::conflict(ErrorCode::Conflict, 'Only a rejected application can be resubmitted.');
                }

                if (! $business) {
                    $business = Business::create([
                        'name' => $data['business_name'], 'slug' => Str::slug($data['business_name']).'-'.Str::lower(Str::ulid()),
                        'phone_number' => $data['mobile_number'], 'status' => 'pending', 'is_verified' => false, 'is_online' => false,
                        'timezone' => 'Asia/Kathmandu', 'home_service' => $data['home_service_location'] !== null, 'location_service' => true,
                        'signup_from' => $data['signup_from'] ?? null,
                    ]);
                    $business->members()->attach($user->id, ['role' => 'owner', 'is_active' => true]);
                    $user->assignRole('owner');
                }
                $documents = $application?->documents ?? [];
                foreach (BusinessOnboarding::DOCUMENTS as $kind) {
                    if (! isset($data[$kind])) {
                        if (! isset($documents[$kind]) || ! Storage::disk('local')->exists($documents[$kind]['path'])) {
                            throw ApiException::validation('Attach all seven documents.', [$kind => ['Choose this document again.']]);
                        }

                        continue;
                    }
                    $file = $data[$kind];
                    $path = $file->store('business-onboarding/'.$business->id, 'local');
                    if ($path === false) {
                        throw new RuntimeException('Unable to store onboarding document.');
                    }
                    $newFiles[] = $path;
                    if (isset($documents[$kind])) {
                        $oldFiles[] = $documents[$kind]['path'];
                    }
                    $documents[$kind] = ['path' => $path, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'mime_type' => $file->getMimeType()];
                }
                $application ??= new BusinessOnboarding(['business_id' => $business->id, 'owner_user_id' => $user->id, 'revision' => 0]);
                $application->fill([
                    'form_data' => Arr::except($data, BusinessOnboarding::DOCUMENTS), 'documents' => $documents,
                    'status' => 'submitted', 'revision' => $application->revision + 1, 'submitted_at' => now(),
                    'reviewed_at' => null, 'reviewed_by' => null, 'rejection_reason' => null,
                ])->save();
                $businessDocuments = [];
                foreach (BusinessOnboarding::BUSINESS_DOCUMENTS as $kind => $legacyKind) {
                    $businessDocuments[$legacyKind] = $documents[$kind]['path'];
                }
                $business->forceFill([
                    'name' => $data['business_name'], 'phone_number' => $data['mobile_number'],
                    'home_service' => $data['home_service_location'] !== null, 'kyc_documents' => $businessDocuments,
                    'status' => 'pending', 'is_online' => false, 'is_verified' => false,
                ])->save();
                $response = ['success' => true, 'message' => 'Business registration submitted for verification.', 'data' => $this->payload($user, $business)];
                $status = $resubmit ? 202 : 201;
                IdempotencyKey::create([
                    'user_id' => $user->id, 'operation' => $operation, 'key' => $key, 'request_hash' => $hash,
                    'response_code' => $status, 'response_json' => $response, 'expires_at' => now()->addDay(),
                ]);
                AuditLogger::record($user, $resubmit ? 'business.onboarding_resubmitted' : 'business.onboarding_submitted', 'business', $business->id, null, ['revision' => $application->revision]);

                return [$status, $response];
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($newFiles);
            throw $error;
        }
        // Keep previous files until the entire submission transaction has committed.
        if ($oldFiles !== []) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($oldFiles));
        }

        return $result;
    }

    public function review(User $reviewer, Business $business, string $action, ?string $reason): void
    {
        $application = BusinessOnboarding::where('business_id', $business->id)->firstOrFail();
        DB::transaction(function () use ($reviewer, $business, $action, $reason, $application): void {
            User::whereKey($application->owner_user_id)->lockForUpdate()->firstOrFail();
            $locked = BusinessOnboarding::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'submitted') {
                throw ApiException::conflict(ErrorCode::Conflict, 'Only a submitted application can be reviewed.');
            }
            $before = ['status' => $locked->status];
            $locked->fill(['status' => $action === 'verify' ? 'verified' : 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => $action === 'reject' ? $reason : null])->save();
            $business->forceFill(['is_verified' => $action === 'verify', 'status' => $action === 'verify' ? 'active' : 'pending', 'is_online' => false])->save();
            AuditLogger::record($reviewer, 'business.'.$action, 'business', $business->id, $before, ['status' => $locked->status], $reason);
        });
    }
}
