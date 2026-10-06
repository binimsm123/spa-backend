<?php

use App\Services\SparrowSmsService;
use Illuminate\Support\Facades\Http;

it('sends a Nepali SMS using Sparrow form parameters', function (): void {
    config()->set('services.sparrow.token', 'test-token');
    config()->set('services.sparrow.sender', 'SPA');
    Http::fake(['https://api.sparrowsms.com/*' => Http::response(['count' => 1, 'response_code' => 200], 200)]);

    app(SparrowSmsService::class)->send('+9779812345678', 'Your code is 1234.');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.sparrowsms.com/v2/sms/'
        && $request['token'] === 'test-token'
        && $request['from'] === 'SPA'
        && $request['to'] === '9812345678'
        && $request['text'] === 'Your code is 1234.');
});
