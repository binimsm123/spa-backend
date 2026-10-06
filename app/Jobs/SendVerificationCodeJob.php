<?php

namespace App\Jobs;

use App\Services\SparrowSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendVerificationCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $mobile,
        public readonly string $message,
    ) {}

    public function handle(SparrowSmsService $sms): void
    {
        $sms->send($this->mobile, $this->message);
    }
}
