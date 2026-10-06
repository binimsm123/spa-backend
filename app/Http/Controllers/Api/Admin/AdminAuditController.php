<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = AuditLog::query()
            ->with('actor')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', $a))
            ->when($request->query('actor_user_id'), fn ($q, $id) => $q->where('actor_user_id', $id))
            ->when($request->query('target_type'), fn ($q, $t) => $q->where('target_type', $t))
            ->orderByDesc('created_at');

        $logs = $query->paginate($perPage);

        return $this->paginated(AuditLogResource::collection($logs));
    }
}
