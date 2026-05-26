<?php

namespace App\Services\Feedback\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when AccountCapabilities denies the can_submit_feedback gate.
 * Today this is unreachable (capability is always true); the gate exists
 * so a future per-user feedback ban can disable abusers.
 */
class FeedbackNotAllowedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Feedback submission is disabled for this account.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 403);
    }
}
