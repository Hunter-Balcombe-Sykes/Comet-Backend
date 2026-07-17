<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

// V2: Abstract base controller. Provides success(), error(), and paginated() response helpers for all API endpoints.
abstract class ApiController extends Controller
{
    /**
     * Return a success JSON response.
     *
     * Signature: success($data, $status) — no message argument.
     * Common footgun: success($data, 'message', 200) passes 'message' as $status
     * and silently drops 200. Pass only data + integer status code.
     */
    protected function success($data = null, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    /**
     * Return a success JSON response with a public CDN cache header.
     *
     * Same body shape as success() — for unauthenticated, aggressively cacheable
     * config endpoints, so they go through the standard helper rather than
     * hand-rolling response()->json()->header().
     */
    protected function successCached($data = null, int $maxAge = 3600): JsonResponse
    {
        return response()->json($data)
            ->header('Cache-Control', "public, max-age={$maxAge}");
    }

    /**
     * Return error response with message.
     *
     * $extra keys are merged into the top-level response body alongside `message`.
     * Use it to include machine-readable discriminators that the frontend keys on:
     *   - MFA gate:    $extra = ['code' => 'mfa_fresh_required']
     *   - Report gate: $extra = ['error' => 'INVALID_TARGET']
     *
     * `message` and `errors` cannot be overwritten by $extra (they are set after
     * the merge so callers cannot clobber the standard envelope keys).
     */
    protected function error(string $message, int $status = 400, array $errors = [], array $extra = []): JsonResponse
    {
        // Merge caller-supplied discriminators first; then enforce standard keys so
        // $extra can never shadow `message` or `errors`.
        $response = array_diff_key($extra, array_flip(['message', 'errors']));
        $response['message'] = $message;

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }
}
