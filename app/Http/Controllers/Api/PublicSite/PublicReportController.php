<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSite\PublicReportRequest;
use App\Http\Resources\Moderation\ReportReceiptResource;
use App\Services\Moderation\ContentReportService;
use App\Services\Moderation\ReportTargetNotFound;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Captcha verification is handled upstream by the `bot.token:report` middleware
 * (App\Http\Middleware\VerifyBotToken). The middleware uses the existing
 * CaptchaManager + CircuitBreaker stack and emits its own rejections — this
 * controller only sees requests that have already passed bot protection.
 */
class PublicReportController extends Controller
{
    public function __construct(
        private readonly ContentReportService $reports,
    ) {}

    public function submit(PublicReportRequest $request): JsonResponse|ReportReceiptResource
    {
        try {
            $result = $this->reports->submit($request->toDto());
        } catch (ReportTargetNotFound) {
            return response()->json([
                'error'   => 'INVALID_TARGET',
                'message' => "We couldn't find that page.",
            ], 422);
        } catch (QueryException $e) {
            if ($this->isDedupViolation($e)) {
                return response()->json([
                    'error'   => 'DUPLICATE_REPORT',
                    'message' => "You've already reported this.",
                ], 409);
            }
            throw $e;
        }

        return (new ReportReceiptResource($result))->response()->setStatusCode(202);
    }

    private function isDedupViolation(QueryException $e): bool
    {
        return str_contains((string) $e->getMessage(), 'case_signals_dedup_uniq');
    }
}
