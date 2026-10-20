<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared behavior for /api/v1 controllers.
 *
 * Controllers stay thin: validate (Form Request), check the token
 * ability, delegate to the existing application Service with
 * AiSource::Api, and return a Resource via ApiResponse.
 */
abstract class ApiController extends Controller
{
    /**
     * 403 response when the bearer token lacks the required ability.
     *
     * Tokens created via `actingAs($user, 'sanctum')` in tests carry no
     * access token instance and are treated as fully privileged; only a
     * real token that exists and lacks the ability is denied.
     */
    protected function denyWithoutAbility(Request $request, string $ability): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::problem('unauthenticated', 401, 'Unauthenticated.');
        }

        if (method_exists($user, 'currentAccessToken')
            && $user->currentAccessToken() !== null
            && ! $user->tokenCan($ability)) {
            return ApiResponse::problem('insufficient_ability', 403, 'Forbidden.', 'Insufficient token abilities.');
        }

        return null;
    }
}
