<?php

use App\AI\Exceptions\AiConnectionException;
use App\AI\Exceptions\AiContentPolicyException;
use App\AI\Exceptions\AiContextLimitException;
use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiTimeoutException;
use App\AI\Exceptions\AiUnavailableException;
use App\AI\Exceptions\AiUsageLimitException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('conversations.create'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Unified /api/v1 error contract: RFC 9457 Problem Details
        // (`type`/`title`/`status`/`detail`) plus our stable `code`
        // extension and case members (`errors`, `retry_after`).
        // Never leaks keys, payloads, prompts, or stack traces — AI
        // failures surface only via the safe userMessage() mapping.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::problem(
                'validation_failed',
                422,
                'Validation failed.',
                'The given data was invalid.',
                ['errors' => $e->errors()]
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::problem('unauthenticated', 401, 'Unauthenticated.');
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::problem(
                'forbidden',
                403,
                'Forbidden.',
                $e->getMessage() !== '' ? $e->getMessage() : 'This action is forbidden.'
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::problem('not_found', 404, 'Not found.');
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::problem('not_found', 404, 'Not found.');
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::problem(
                'rate_limited',
                429,
                'Too many requests.',
                'Too many requests. Please slow down and try again.',
                array_filter(['retry_after' => $e->getHeaders()['Retry-After'] ?? null])
            );
        });

        $exceptions->render(function (AiException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof AiUsageLimitException) {
                return ApiResponse::problem(
                    'usage_limit',
                    429,
                    'AI usage limit reached.',
                    $e->userMessage(),
                    array_filter(['retry_after' => $e->retryAfterSeconds()])
                );
            }

            if ($e instanceof AiRateLimitException) {
                $code = $e->provider() === 'app' ? 'rate_limited' : 'provider_rate_limited';

                return ApiResponse::problem(
                    $code,
                    429,
                    'Too many requests.',
                    $e->userMessage(),
                    array_filter(['retry_after' => $e->retryAfterSeconds()])
                );
            }

            if ($e instanceof AiContextLimitException) {
                return ApiResponse::problem('context_limit', 422, 'Conversation context limit reached.', $e->userMessage());
            }

            if ($e instanceof AiContentPolicyException) {
                return ApiResponse::problem('content_policy', 422, 'Content policy refusal.', $e->userMessage());
            }

            if ($e instanceof AiConnectionException
                || $e instanceof AiTimeoutException
                || $e instanceof AiUnavailableException) {
                return ApiResponse::problem('provider_unavailable', 503, 'AI provider unavailable.', $e->userMessage());
            }

            return ApiResponse::problem('provider_failed', 502, 'AI provider failed.', $e->userMessage());
        });
    })->create();
