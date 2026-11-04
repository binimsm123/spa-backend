<?php

namespace App\Http\Requests\Business;

use App\Enums\BusinessUserRole;
use App\Models\BusinessUser;
use App\Support\ApiException;
use Illuminate\Foundation\Http\FormRequest;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membership = $this->user()->membershipFor($this->route('business'));

        abort_unless($membership !== null, 403, 'You do not manage this business.');

        if (! $this->isMethodSafe()) {
            abort_unless(
                in_array($membership->role, [BusinessUserRole::Owner->value, BusinessUserRole::Manager->value], true),
                403,
                'Your role cannot perform this action.',
            );
        }

        $this->staffMembership();

        return true;
    }

    public function staffMembership(bool $lock = false): BusinessUser
    {
        $query = $this->route('user')->businessMemberships()->where('business_id', $this->route('business')->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw ApiException::notFound('Staff user not found in this business.');
    }
}
