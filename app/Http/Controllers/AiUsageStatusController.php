<?php

namespace App\Http\Controllers;

use App\Services\AI\AiUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageStatusController extends Controller
{
    /**
     * Authoritative application usage-limit state for the chat UI.
     *
     * The banner/countdown are display only: every enable decision
     * re-checks here, and every AI generation re-enforces the policy
     * server-side in MessageService regardless of what the UI shows.
     */
    public function __invoke(Request $request, AiUsageService $usage): JsonResponse
    {
        $remaining = $usage->remainingCooldown($request->user());

        return response()->json([
            'limited' => $remaining !== null,
            'retry_after' => $remaining,
        ]);
    }
}
