<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
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
class PublicReportController extends ApiController
{
    public function __construct(
        private readonly ContentReportService $reports,
    ) {}

    public function submit(PublicReportRequest $request): JsonResponse|ReportReceiptResource
    {
        try {
            $result = $this->reports->submit($request->toDto());
        } catch (ReportTargetNotFound) {
            // 422 + INVALID_TARGET: frontend reads `error` key to display contextual
            // messaging. Use 422 (not 404) — the endpoint is public/unauthenticated and
            // the handle was syntactically valid; this is a business-rule rejection, not
            // an enumeration risk (the report form already reveals the handle was looked up).
            return $this->error("We couldn't find that page.", 422, [], ['error' => 'INVALID_TARGET']);
        } catch (QueryException $e) {
            if ($this->isDedupViolation($e)) {
                return $this->error("You've already reported this.", 409, [], ['error' => 'DUPLICATE_REPORT']);
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
