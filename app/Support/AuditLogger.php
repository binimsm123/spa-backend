<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function record(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): AuditLog {
        /** @var Request $request */
        $request = request();

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before' => $before,
            'after' => $after,
            'request_id' => $request?->header('X-Request-Id'),
            'ip_address' => $request?->ip(),
            'reason' => $reason,
        ]);
    }
}
