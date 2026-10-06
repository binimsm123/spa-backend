<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardAdjustmentRequest;
use App\Http\Requests\Admin\RewardConfigRequest;
use App\Models\RewardConfiguration;
use App\Models\RewardPointTransaction;
use App\Models\User;
use App\Services\RewardService;
use App\Support\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRewardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RewardService $rewards,
    ) {}

    public function configurations(): JsonResponse
    {
        $configs = RewardConfiguration::query()->orderByDesc('created_at')->limit(50)->get();

        return $this->success(['items' => $configs->map(fn (RewardConfiguration $c) => [
            'id' => $c->id,
            'points_per_rupee' => (float) $c->points_per_rupee,
            'basis' => $c->basis,
            'is_active' => (bool) $c->is_active,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'deactivated_at' => $c->deactivated_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ])]);
    }

    public function storeConfiguration(RewardConfigRequest $request): JsonResponse
    {
        $data = $request->validated();

        $config = DB::transaction(function () use ($data, $request): RewardConfiguration {
            // Deactivate the current configuration; never overwrite the old rate.
            RewardConfiguration::query()
                ->where('is_active', true)
                ->update(['is_active' => false, 'deactivated_at' => now()]);

            return RewardConfiguration::query()->create($data + [
                'created_by_user_id' => $request->user()->getKey(),
            ]);
        });

        AuditLogger::record($request->user(), 'reward.configuration_created', 'reward_configuration', $config->getKey(), null, $config->only(['points_per_rupee', 'basis']));

        return $this->success([
            'id' => $config->id,
            'points_per_rupee' => (float) $config->points_per_rupee,
            'basis' => $config->basis,
            'is_active' => (bool) $config->is_active,
        ], 'Reward configuration created.', 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = RewardPointTransaction::query()
            ->with('user')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('created_at');

        return $this->paginated(
            \App\Http\Resources\RewardTransactionResource::collection($query->paginate($perPage))
        );
    }

    public function adjust(RewardAdjustmentRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $transaction = $this->rewards->adjust($user, (int) $data['points'], $data['reason'], $request->user());

        AuditLogger::record($request->user(), 'reward.adjusted', 'user', $user->getKey(), null, ['points' => $data['points']], $data['reason']);

        return $this->success([
            'transaction_id' => $transaction->getKey(),
            'balance_after' => (int) $transaction->balance_after,
        ], 'Reward points adjusted.');
    }
}
