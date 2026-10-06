<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    /**
     * Run an operation under an idempotency key. Replays the stored response
     * when the same principal, operation, key, and request hash repeat.
     *
     * @param  callable(): array{int, array<string, mixed>}  $callback  Returns [status, payload]
     * @return array{int, array<string, mixed>, bool} [status, payload, replayed]
     */
    public function run(int|string $userId, string $operation, ?string $key, string $requestHash, callable $callback): array
    {
        if ($key === null || $key === '') {
            [$status, $payload] = $callback();

            return [$status, $payload, false];
        }

        $existing = IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('operation', $operation)
            ->where('key', mb_substr($key, 0, 128))
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                throw \App\Support\ApiException::validation(
                    'This idempotency key was already used with a different request body.',
                    ['Idempotency-Key' => ['The idempotency key was already used with a different request.']],
                );
            }

            if ($existing->response_json !== null) {
                return [$existing->response_code ?? 200, $existing->response_json, true];
            }

            // Concurrent first attempt still in flight.
            throw \App\Support\ApiException::conflict(\App\Support\ErrorCode::Conflict, 'A request with this idempotency key is already in progress.');
        }

        try {
            DB::beginTransaction();

            IdempotencyKey::query()->create([
                'user_id' => $userId,
                'operation' => $operation,
                'key' => mb_substr($key, 0, 128),
                'request_hash' => $requestHash,
                'expires_at' => now()->addDay(),
            ]);

            [$status, $payload] = $callback();

            IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('operation', $operation)
                ->where('key', mb_substr($key, 0, 128))
                ->update([
                    'response_code' => $status,
                    'response_json' => $payload,
                ]);

            DB::commit();

            return [$status, $payload, false];
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    public static function requestHash(array $payload): string
    {
        return hash('sha256', json_encode($payload) ?: '');
    }
}
