<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $referrer = $this->user;
        $referrals = $this->referrals;

        return [
            'code' => $this->code,
            'share_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/register?ref='.$this->code,
            'is_active' => (bool) $this->is_active,
            'stats' => [
                'invited' => $referrals->count(),
                'qualified' => $referrals->where('status', 'qualified')->count(),
                'rewarded' => $referrals->where('status', 'rewarded')->count(),
            ],
            'terms' => 'Earn reward points when a friend registers with your code and completes their first paid booking.',
        ];
    }
}
