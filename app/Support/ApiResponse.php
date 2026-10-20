<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Single place that defines the public JSON contract for /api/v1.
 *
 * Success is always one of three shapes:
 * - single resource:  `{"data": {...}}`
 * - collection:       `{"data": [...], "meta": {...}}`
 * - action:           `{"data": null, "message": "..."}`
 *
 * Errors follow RFC 9457 Problem Details (`application/problem+json`
 * semantics): `type`/`title`/`status`/`detail`, plus our stable
 * machine-readable `code` extension and case-specific members such
 * as `errors` or `retry_after`. Individual controllers must not
 * invent their own shapes.
 */
class ApiResponse
{
    /**
     * Single resource response, with optional `meta` for companion
     * state (e.g. regeneration side effects).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function resource(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Collection response. Pagination state always lives under `meta`.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function collection(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta], $status);
    }

    /**
     * Action response for operations with no resource to return
     * (deletes, logout, bulk operations).
     */
    public static function action(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['data' => null, 'message' => $message], $status);
    }

    /**
     * RFC 9457 problem response. `code` is our stable application
     * extension; `type` identifies it as a dereferenceable URI.
     *
     * @param  array<string, mixed>  $extra  Case members like `errors` or `retry_after`.
     */
    public static function problem(string $code, int $status, string $title, string $detail = '', array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'type' => rtrim((string) config('app.url'), '/').'/problems/'.$code,
            'title' => $title,
            'status' => $status,
            'detail' => $detail !== '' ? $detail : $title,
            'code' => $code,
        ], $extra), $status);
    }
}
