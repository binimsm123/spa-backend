<?php

use App\Support\RetryingRateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;

it('retries database cache deadlocks while preserving throttle counters', function (): void {
    $store = new class extends ArrayStore
    {
        public int $calls = 0;

        public function add($key, $value, $seconds)
        {
            if (++$this->calls === 1) {
                $previous = new PDOException('Deadlock', 40001);
                $previous->errorInfo = ['40001', 1213, 'Deadlock'];
                throw new QueryException('mysql', 'insert into cache', [], $previous);
            }

            if ($this->get($key) !== null) {
                return false;
            }

            return $this->put($key, $value, $seconds);
        }
    };
    $limiter = new RetryingRateLimiter(new Repository($store));
    expect($limiter->hit('management-test', 60))->toBe(1)
        ->and($limiter->tooManyAttempts('management-test', 1))->toBeTrue();
});

it('does not retry other database errors or repeat cache writes indefinitely', function (string $state, int $calls): void {
    $store = new class($state) extends ArrayStore
    {
        public int $calls = 0;

        public function __construct(private readonly string $state) {}

        public function add($key, $value, $seconds)
        {
            $this->calls++;
            $previous = new PDOException('Database failure');
            $previous->errorInfo = [$this->state, 0, 'Database failure'];
            throw new QueryException('mysql', 'insert into cache', [], $previous);
        }
    };
    $limiter = new RetryingRateLimiter(new Repository($store));
    expect(fn () => $limiter->hit('management-test'))->toThrow(QueryException::class);
    expect($store->calls)->toBe($calls);
})->with(['unrelated error' => ['23000', 1], 'repeated deadlock' => ['40001', 3]]);
