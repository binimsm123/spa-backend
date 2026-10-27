<?php

namespace App\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;

/** Retry cache-counter deadlocks without ever repeating a controller action. */
class RetryingRateLimiter extends RateLimiter
{
    public function increment($key, $decaySeconds = 60, $amount = 1)
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::increment($key, $decaySeconds, $amount);
            } catch (QueryException $error) {
                if ($attempt >= 2 || ! in_array((string) ($error->errorInfo[0] ?? ''), ['40001', '40P01'], true)) {
                    throw $error;
                }
                usleep(10000 * ($attempt + 1));
            }
        }
    }
}
