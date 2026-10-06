<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SparrowSmsService
{
    public function send(string $mobile, string $message): Response
    {
        $recipient = preg_replace('/^\+977/', '', trim($mobile)) ?: $mobile;

        if (! preg_match('/^9\d{9}$/', $recipient)) {
            throw new RuntimeException('Sparrow SMS requires a valid Nepali mobile number.');
        }

        $response = Http::asForm()
            ->timeout((int) config('services.sparrow.timeout', 10))
            ->post(config('services.sparrow.endpoint'), [
                'token' => config('services.sparrow.token'),
                'from' => config('services.sparrow.sender'),
                'to' => $recipient,
                'text' => $message,
            ]);

        if ($response->failed() || (int) $response->json('response_code', 0) !== 200) {
            throw new RuntimeException('Sparrow SMS rejected the message.');
        }

        return $response;
    }
}
