<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferralResource;
use App\Models\ReferralCode;
use App\Models\ReferralShare;
use App\Models\ReferralReward;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    use ApiResponse;

    public function me(Request $request): JsonResponse
    {
        $code = ReferralCode::query()->firstOrCreate(
            ['user_id' => $request->user()->getKey()],
            ['code' => strtoupper(Str::random(8)), 'is_active' => true],
        );

        $code->load(['referrals', 'user']);

        return $this->resource(ReferralResource::make($code), 'Referral program loaded.');
    }

    public function share(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:sms,email,whatsapp,viber,messenger,link'],
        ]);

        $code = ReferralCode::query()->firstOrCreate(
            ['user_id' => $request->user()->getKey()],
            ['code' => strtoupper(Str::random(8)), 'is_active' => true],
        );

        $shareUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/register?ref='.$code->code;
        $trackingId = (string) Str::ulid();

        ReferralShare::query()->create([
            'user_id' => $request->user()->getKey(),
            'referral_code_id' => $code->getKey(),
            'channel' => $data['channel'],
            'tracking_id' => $trackingId,
            'created_at' => now(),
        ]);

        $text = match ($data['channel']) {
            'sms' => "Book your next spa day with my code {$code->code}: $shareUrl",
            'email' => "Hi! Try the best spas in town with my referral code {$code->code}: $shareUrl",
            default => "Spa day? Use my code {$code->code} for a discount: $shareUrl",
        };

        return $this->success([
            'channel' => $data['channel'],
            'referral_code' => $code->code,
            'share_url' => $shareUrl,
            'share_message' => $text,
            'tracking_id' => $trackingId,
        ], 'Referral share payload created.');
    }

    public function rewards(Request $request): JsonResponse
    {
        $rewards = ReferralReward::query()
            ->where('user_id', $request->user()->getKey())
            ->with('referral.referredUser')
            ->latest('earned_at')
            ->get();

        return $this->success([
            'items' => $rewards->map(fn (ReferralReward $r) => [
                'id' => $r->id,
                'reward_type' => $r->reward_type,
                'value' => (int) $r->value,
                'currency' => $r->currency,
                'status' => $r->status,
                'earned_at' => $r->earned_at?->toIso8601String(),
                'referred_user' => $r->referral?->referredUser?->display_name,
            ]),
        ]);
    }
}
