<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    /**
     * Successful envelope per the API contract.
     */
    protected function success(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Successful envelope from an API Resource.
     */
    protected function resource(
        JsonResource $resource,
        string $message = 'Request completed successfully.',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        $payload = $resource->response()->getData(true);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payload['data'] ?? null,
        ], $status);
    }

    /**
     * Paginated envelope wrapping a resource collection.
     */
    protected function paginated(
        JsonResource $resource,
        string $message = 'Request completed successfully.',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        $payload = $resource->response()->getData(true);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'items' => $payload['data'] ?? [],
                'pagination' => [
                    'page' => $payload['meta']['current_page'] ?? 1,
                    'per_page' => $payload['meta']['per_page'] ?? (int) config('app.per_page', 20),
                    'total' => $payload['meta']['total'] ?? 0,
                    'total_pages' => $payload['meta']['last_page'] ?? 1,
                    'has_more' => ($payload['meta']['current_page'] ?? 1) < ($payload['meta']['last_page'] ?? 1),
                ],
            ],
        ], $status);
    }

    /**
     * Accepted (asynchronous) envelope.
     */
    protected function accepted(mixed $data = null, string $message = 'Request accepted.'): JsonResponse
    {
        return $this->success($data, $message, Response::HTTP_ACCEPTED);
    }

    /**
     * Created envelope.
     */
    protected function created(mixed $data = null, string $message = 'Resource created successfully.'): JsonResponse
    {
        return $this->success($data, $message, Response::HTTP_CREATED);
    }
}
