<?php

namespace App\Services\Feedback\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a user submits an identical feedback message inside the
 * duplicate-window. Renders as 429 so the frontend treats it the same as
 * the rate-limit response.
 */
class DuplicateFeedbackException extends HttpException
{
    public function __construct()
    {
        parent::__construct(429, 'Duplicate feedback submitted moments ago.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 429);
    }
}
