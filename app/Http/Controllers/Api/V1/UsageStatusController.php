<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AI\AiUsageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageStatusController extends ApiController
{
    /**
     * Authoritative AI usage-limit state for the token owner.
     *
     * Display only: every AI generation re-enforces the policy
     * server-side in MessageService regardless of what this shows.
     */
    public function __invoke(Request $request, AiUsageService $usage): JsonResponse
    {
        $remaining = $usage->remainingCooldown($request->user());

        return ApiResponse::resource([
            'limited' => $remaining !== null,
            'retry_after' => $remaining,
        ]);
    }
}
