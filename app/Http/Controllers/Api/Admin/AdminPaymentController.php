<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Payment::query()
            ->with(['booking.business', 'user'])
            ->when($request->query('gateway'), fn ($q, $g) => $q->where('gateway', $g))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('business_id'), fn ($q, $id) => $q->whereHas('booking', fn ($b) => $b->where('business_id', $id)))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at');

        return $this->paginated(PaymentResource::collection($query->paginate($perPage)));
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['booking.items', 'user']);

        return $this->resource(PaymentResource::make($payment));
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $refunded = $this->payments->refund($payment, $data['reason']);

        return $this->resource(PaymentResource::make($refunded), 'Payment refunded.');
    }
}
