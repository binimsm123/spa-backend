<?php

namespace App\Http\Middleware;

use App\Services\Payments\EsewaGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleEsewaSuccess
{
    public function __construct(private readonly EsewaGateway $gateway) {}

    public function handle(Request $request, Closure $next): Response
    {
        $data = $request->validate(['data' => ['required', 'string', 'max:8192']]);
        $verified = $this->gateway->verifyCallback($data['data']);
        $request->attributes->set('payment', $verified['payment']);
        $request->attributes->set('verification', $verified['verification']);

        return $next($request);
    }
}
