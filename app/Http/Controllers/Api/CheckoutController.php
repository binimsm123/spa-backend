<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Http\Dto\Api\CheckoutData;
use App\Http\Dto\Api\CompletedSessionData;
use App\Http\Dto\Api\ReviewData;
use App\Http\Dto\Api\TipCheckoutData;
use App\Http\Requests\Checkout\QuoteRequest;
use App\Http\Requests\Checkout\StartCheckoutRequest;
use App\Http\Requests\Checkout\TipCheckoutRequest;
use App\Http\Requests\Review\SubmitReviewRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\CheckoutQuoteResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ReviewResource;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CheckoutQuote;
use App\Models\Review;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CheckoutController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
    ) {}

    /**
     * GET /bookings/{booking}/checkout — server-owned summary.
     */
    public function summary(Request $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        $booking->load(['items', 'business', 'payments']);

        return $this->success((new CheckoutData($booking))->summary(), 'Checkout summary loaded.');
    }

    /**
     * POST /bookings/{booking}/checkout/quote
     */
    public function quote(QuoteRequest $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        $quote = $this->checkout->quote(
            $request->user(),
            $booking,
            (int) $request->validated('tip_minor', 0),
            $request->validated('promo_code'),
        );

        return $this->success(CheckoutData::quote($quote), 'Checkout quote updated.');
    }

    /**
     * POST /bookings/{booking}/checkout
     */
    public function start(StartCheckoutRequest $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        $quote = CheckoutQuote::query()->findOrFail($request->validated('quote_id'));

        $payment = $this->checkout->startCheckout(
            $request->user(),
            $booking,
            $quote,
            $request->validated('gateway'),
        );

        return $this->success(CheckoutData::payment($payment), 'Checkout created.');
    }

    /**
     * GET /bookings/{booking}/completion
     */
    public function completion(Request $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        if (! $booking->isCompleted()) {
            throw ApiException::conflict(ErrorCode::BookingNotCompleted, 'Completion details are only available for completed appointments.');
        }

        $booking->load(['items', 'business', 'businessLocation', 'payments', 'review']);

        $receiptUrl = url('/receipts/'.$booking->getKey().'?expires='.now()->addMinutes(30)->timestamp.'&signature='.hash_hmac('sha256', $booking->getKey(), config('app.key')));

        return $this->success((new CompletedSessionData($booking, $receiptUrl))->toArray(), 'Completed session loaded.');
    }

    /**
     * POST /bookings/{booking}/review
     */
    public function review(SubmitReviewRequest $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        if (! $booking->isCompleted()) {
            throw ApiException::conflict(ErrorCode::BookingNotCompleted, 'Reviews are only available for completed appointments.');
        }

        $existing = Review::query()->where('booking_id', $booking->getKey())->exists();

        if ($existing) {
            throw ApiException::conflict(ErrorCode::ReviewAlreadySubmitted, 'A review was already submitted for this booking.');
        }

        $review = DB::transaction(function () use ($request, $booking): Review {
            /** @var Review $review */
            $review = Review::query()->create([
                'booking_id' => $booking->getKey(),
                'user_id' => $request->user()->getKey(),
                'business_id' => $booking->business_id,
                'service_id' => $booking->items->first()->service_id,
                'rating' => (int) $request->validated('rating'),
                'tag' => $request->validated('tag'),
                'comments' => $request->validated('comments'),
            ]);

            // Update the branch read-model rating.
            $agg = Review::query()
                ->where('business_id', $booking->business_id)
                ->where('is_hidden', false)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
                ->first();

            $booking->business->forceFill([
                'rating_average' => round((float) $agg->avg_rating, 2),
                'rating_count' => (int) $agg->total,
            ])->save();

            return $review;
        });

        return $this->success((new ReviewData($review))->toArray(), 'Review submitted.', Response::HTTP_CREATED);
    }

    /**
     * POST /bookings/{booking}/tip/checkout
     */
    public function tip(TipCheckoutRequest $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        $tip = $this->payments->tipCheckout(
            $request->user(),
            $booking,
            (int) $request->validated('amount_minor'),
            PaymentGateway::from($request->validated('gateway')),
        );

        return $this->success((new TipCheckoutData($tip->load('payment')))->toArray(), 'Tip checkout created.');
    }

    private function assertOwned(Request $request, Booking $booking): void
    {
        abort_unless($booking->user_id === $request->user()->getKey(), 403);
    }
}
