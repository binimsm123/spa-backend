<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SubmitOnboardingRequest;
use App\Models\Business;
use App\Models\BusinessOnboarding;
use App\Services\BusinessOnboardingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessOnboardingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BusinessOnboardingService $onboarding) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->onboarding->current($request->user()));
    }

    public function submit(SubmitOnboardingRequest $request): JsonResponse
    {
        [$status, $payload] = $this->onboarding->submit($request->user(), $request->validated(), $request->routeIs('business.onboarding.resubmit'));

        return response()->json($payload, $status);
    }

    public function document(Business $business, string $document): StreamedResponse
    {
        abort_unless(in_array($document, BusinessOnboarding::DOCUMENTS, true), 404);
        $application = BusinessOnboarding::where('business_id', $business->id)->firstOrFail();
        $file = $application->documents[$document] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->response($file['path'], $document.'.'.pathinfo($file['path'], PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
