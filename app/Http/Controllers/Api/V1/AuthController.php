<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\AuthResource;
use App\Services\Auth\ApiAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly ApiAuthService $auth) {}

    /**
     * Register exactly like the Web flow: same rules, same
     * persistence, same brute-force budget. The Web session login
     * is replaced by an issued bearer: the API never creates
     * a session.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated());

        $token = $this->auth->issueToken($user);

        return ApiResponse::resource(new AuthResource(['user' => $user, 'token' => $token->plainTextToken]), 201);
    }

    /**
     * Login exactly like the Web flow: same rules, same failure
     * shape, same brute-force budget, stateless verification. The
     * bearer replaces the session cookie.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->auth->authenticate($validated['email'], $validated['password']);

        $token = $this->auth->issueToken($user);

        return ApiResponse::resource(new AuthResource(['user' => $user, 'token' => $token->plainTextToken]));
    }

    /**
     * Revoke only the current bearer: it stops working immediately
     * while the user's other tokens — and any Web session — are
     * left untouched.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::action('Logged out.');
    }
}
