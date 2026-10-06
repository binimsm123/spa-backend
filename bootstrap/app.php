<?php

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ApiException $e, Request $request) {
            return response()->json($e->toResponse(), $e->status());
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => ErrorCode::ValidationError->value,
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication is required.',
                'code' => ErrorCode::Unauthenticated->value,
            ], 401);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
                'code' => ErrorCode::Unauthorized->value,
            ], 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
                'code' => ErrorCode::NotFound->value,
            ], 404);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down.',
                'code' => ErrorCode::Throttled->value,
            ], 429);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() >= 500) {
                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred. Please try again.',
                    'code' => ErrorCode::ServerError->value,
                ], 500);
            }

            return null;
        });
    })->create();
