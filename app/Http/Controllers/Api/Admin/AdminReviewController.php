<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Support\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Review::query()
            ->with(['user', 'service', 'business'])
            ->when($request->query('business_id'), fn ($q, $id) => $q->where('business_id', $id))
            ->when($request->query('rating'), fn ($q, $r) => $q->where('rating', (int) $r))
            ->when($request->query('hidden') === 'true', fn ($q) => $q->where('is_hidden', true))
            ->when($request->query('hidden') === 'false', fn ($q) => $q->where('is_hidden', false))
            ->orderByDesc('created_at');

        return $this->paginated(ReviewResource::collection($query->paginate($perPage)));
    }

    public function show(Review $review): JsonResponse
    {
        $review->load(['user', 'service', 'business', 'booking']);

        return $this->resource(ReviewResource::make($review));
    }

    public function hide(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $before = ['is_hidden' => $review->is_hidden];
        $review->forceFill(['is_hidden' => true, 'hidden_at' => now(), 'hidden_by_user_id' => $request->user()->getKey()])->save();

        AuditLogger::record($request->user(), 'review.hidden', 'review', $review->getKey(), $before, ['is_hidden' => true], $data['reason']);

        return $this->resource(ReviewResource::make($review->refresh()), 'Review hidden.');
    }

    public function restore(Request $request, Review $review): JsonResponse
    {
        $before = ['is_hidden' => $review->is_hidden];
        $review->forceFill(['is_hidden' => false, 'hidden_at' => null, 'hidden_by_user_id' => null])->save();

        AuditLogger::record($request->user(), 'review.restored', 'review', $review->getKey(), $before, ['is_hidden' => false]);

        return $this->resource(ReviewResource::make($review->refresh()), 'Review restored.');
    }
}
