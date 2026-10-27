<?php

namespace App\Services;

use App\Enums\BusinessStatus;
use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\IdempotencyKey;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Serializes business mutations and owns file cleanup across transaction failures. */
class BusinessManagement
{
    private array $newFiles = [];

    private array $oldFiles = [];

    public function authorize(Request $request, Business $business, bool $write = false): void
    {
        abort_unless($request->user()->belongsToBusiness($business), 403, 'You do not manage this business.');
        if ($write) {
            abort_unless($request->user()->hasBusinessRole($business, BusinessUserRole::Owner, BusinessUserRole::Manager), 403, 'Your role cannot manage services or staff.');
            if (! $business->is_verified || $business->status !== BusinessStatus::Active->value) {
                throw ApiException::conflict(ErrorCode::Conflict, 'An active business with verified KYC is required to make changes.');
            }
        }
    }

    public function mutate(Request $request, Business $business, string $operation, array $data, callable $callback, bool $requireKey = true): JsonResponse
    {
        $this->newFiles = $this->oldFiles = [];
        $key = $request->header('Idempotency-Key');
        if ($requireKey && (! is_string($key) || $key === '' || strlen($key) > 128)) {
            throw ApiException::validation('A valid Idempotency-Key is required.', ['Idempotency-Key' => ['Use a unique key up to 128 characters.']]);
        }
        if ($key !== null && strlen($key) > 128) {
            throw ApiException::validation('Idempotency-Key is too long.');
        }
        $hash = hash('sha256', json_encode([$business->id, $this->fingerprint($data)], JSON_THROW_ON_ERROR));
        try {
            [$status, $payload] = DB::transaction(function () use ($request, $business, $operation, $key, $hash, $callback): array {
                $fresh = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
                $this->authorize($request, $fresh, true);
                $existing = $key ? IdempotencyKey::where('user_id', $request->user()->id)->where('operation', $operation)->where('key', $key)->first() : null;
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw ApiException::conflict(ErrorCode::Conflict, 'This idempotency key was already used with different fields or files.');
                    }

                    return [$existing->response_code, $existing->response_json];
                }
                [$status, $data] = $callback();
                $payload = ['success' => true, 'message' => 'Request completed successfully.', 'data' => $data];
                if ($key) {
                    IdempotencyKey::create([
                        'user_id' => $request->user()->id, 'operation' => $operation, 'key' => $key,
                        'request_hash' => $hash, 'response_code' => $status, 'response_json' => $payload,
                        'expires_at' => now()->addDay(),
                    ]);
                }

                return [$status, $payload];
            });
        } catch (Throwable $error) {
            foreach ($this->newFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $error;
        }
        foreach ($this->oldFiles as [$disk, $path]) {
            // Delete only files created by this feature; legacy paths can be shared.
            if (str_starts_with($path, 'service-photos/') || str_starts_with($path, 'staff-portraits/')) {
                Storage::disk($disk)->delete($path);
            }
        }

        return response()->json($payload, $status);
    }

    public function upload(UploadedFile $file, string $disk, string $folder): string
    {
        $filename = Str::random(40).'.'.$file->extension();
        $path = $folder.'/'.$filename;
        $this->newFiles[] = [$disk, $path];
        if (! Storage::disk($disk)->putFileAs($folder, $file, $filename)) {
            throw new RuntimeException('Unable to store the uploaded image. Please retry.');
        }

        return $path;
    }

    public function removeAfterCommit(string $disk, ?string $path): void
    {
        if ($path) {
            $this->oldFiles[] = [$disk, $path];
        }
    }

    private function fingerprint(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return ['sha256' => hash_file('sha256', $value->getRealPath()), 'size' => $value->getSize(), 'mime' => $value->getMimeType()];
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->fingerprint($item), $value);
        }

        return $value;
    }
}
