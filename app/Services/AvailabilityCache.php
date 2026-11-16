<?php

namespace App\Services;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class AvailabilityCache
{
    /**
     * @template TValue of array
     *
     * @param  Closure(): TValue  $calculate
     * @return TValue
     */
    public function remember(string $key, Closure $calculate): array
    {
        $ttl = (int) config('spa.booking.availability_cache_seconds');
        if ($ttl <= 0) {
            return $calculate();
        }

        $cache = Cache::store(config('spa.booking.availability_cache_store'));
        $cached = $cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            return $cache->lock($key.':rebuild', (int) config('spa.booking.availability_lock_seconds'))
                ->block((int) config('spa.booking.availability_lock_wait_seconds'), function () use ($cache, $key, $ttl, $calculate): array {
                    $cached = $cache->get($key);
                    if ($cached !== null) {
                        return $cached;
                    }

                    $result = $calculate();
                    $cache->put($key, $result, $ttl);

                    return $result;
                });
        } catch (LockTimeoutException) {
            $cached = $cache->get($key);
            if ($cached !== null) {
                return $cached;
            }

            throw new ApiException(ErrorCode::Throttled, 'Availability is being refreshed. Please try again shortly.');
        }
    }
}
