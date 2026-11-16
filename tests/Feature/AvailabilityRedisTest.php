<?php

use App\Services\AvailabilityCache;
use App\Services\AvailabilityService;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('round trips availability through Redis without serializing model objects', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    config(['spa.booking.availability_cache_store' => 'redis']);
    [$business, $service] = createBookableBusinessWithService();
    $availability = app(AvailabilityService::class);
    $first = $availability->availableDatesFor($business, $service);
    $this->expectsDatabaseQueryCount(0);

    $cached = $availability->availableDatesFor($business, $service);

    expect($cached)->toEqual($first);
    expect($cached['availability'])->toHaveCount(5);
})->group('redis')->skip(! extension_loaded('redis'), 'Run Redis integration tests inside the Sail container.');

it('rebuilds a cold Redis entry only once across concurrent PHP processes', function (): void {
    $key = 'availability-test:'.Str::uuid();
    $worker = static function () use ($key): array {
        config([
            'spa.booking.availability_cache_store' => 'redis',
            'spa.booking.availability_cache_seconds' => 20,
            'spa.booking.availability_lock_wait_seconds' => 10,
        ]);
        Event::listen(CacheMissed::class, function (CacheMissed $event) use ($key): void {
            if ($event->key === $key) {
                Redis::connection('cache')->rpush($key.':misses', 'miss');
            }
        });

        return app(AvailabilityCache::class)->remember($key, function () use ($key): array {
            Redis::connection('cache')->incr($key.':builds');
            // Synchronize all three initial misses plus the lock owner's second check.
            for ($miss = 0; $miss < 4; $miss++) {
                if (empty(Redis::connection('cache')->blpop([$key.':misses'], 5))) {
                    throw new RuntimeException('Concurrent cache requests did not reach the rebuild barrier.');
                }
            }

            return ['availability' => [['date' => '2026-11-03', 'slots' => [['starts_at' => '09:00', 'ends_at' => '10:00']]]]];
        });
    };

    try {
        $worker = Closure::bind($worker, null, null);
        $results = Concurrency::driver('process')->run([$worker, $worker, $worker]);

        expect(Redis::connection('cache')->get($key.':builds'))->toBe('1');
        expect($results)->toHaveCount(3);
        foreach ($results as $result) {
            expect($result)->toBe(['availability' => [['date' => '2026-11-03', 'slots' => [['starts_at' => '09:00', 'ends_at' => '10:00']]]]]);
        }
    } finally {
        Cache::store('redis')->forget($key);
        Redis::connection('cache')->del($key.':misses', $key.':builds');
    }
})->group('redis')->skip(! extension_loaded('redis'), 'Run Redis integration tests inside the Sail container.');
