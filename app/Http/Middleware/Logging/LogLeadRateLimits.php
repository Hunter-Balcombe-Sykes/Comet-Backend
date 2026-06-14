<?php

namespace App\Http\Middleware\Logging;

use App\Http\Controllers\Concerns\HashesClientData;
use App\Models\Analytics\LeadSubmission;
use App\Services\Analytics\AnalyticsEventSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// V2: Logs rate-limited lead submissions to analytics.lead_submissions for abuse monitoring.
//
// Invariants:
//   - The 429 response must reach the client even if the analytics insert fails (LIFE-1/SCALE-1).
//     Write happens in terminate() — after fastcgi_finish_request() — so DB hiccups can't turn a
//     429 into a 500.
//   - Auto-retry bursts (browsers that fire 2-3 retries on a single rate-limit hit) must produce
//     a single analytics row (LIFE-2). A 10-second Redis SETNX keyed by (ip_hash, subdomain)
//     short-circuits duplicates without blocking genuinely distinct submissions.
//   - The stored Referer is origin + path only, capped at 512 chars (SEC-3). Query strings from
//     marketing tools routinely embed subscriber emails / UTM PII — keeping only origin + path
//     retains forensic value without the GDPR retention burden.
class LogLeadRateLimits
{
    use HashesClientData;

    private const DEDUP_TTL_SECONDS = 10;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    /**
     * Runs after the response is flushed to the client. Any exception here is swallowed
     * so analytics-pipeline outages can't corrupt rate-limited responses.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() !== 429) {
            return;
        }

        try {
            $subdomain = $this->resolveSubdomain($request);
            $ipHash = $this->hashIp($request->ip());

            // Dedup auto-retry bursts. Cache::add is atomic SETNX — returns false if the key
            // already exists, meaning we already logged this source in the last 10s.
            $dedupKey = "partna:rate-limit-logged:{$ipHash}:".($subdomain ?? 'unknown');
            if (! Cache::add($dedupKey, 1, self::DEDUP_TTL_SECONDS)) {
                return;
            }

            LeadSubmission::query()->create([
                'occurred_at' => now(),
                'subdomain' => $subdomain,
                'ip_hash' => $ipHash,
                // PRIV-5/6: cap the UA and strip referrer query strings (UTM PII).
                'user_agent' => AnalyticsEventSanitizer::userAgent($request->userAgent()),
                'referrer' => AnalyticsEventSanitizer::referrer($request->headers->get('referer')),
                'outcome' => 'rate_limited',
                'form_started_at_ms' => null,
            ]);
        } catch (Throwable $e) {
            // Breadcrumb only — Nightwatch will surface repeated failures via its
            // log-channel aggregation, but a single failure does not page.
            Log::warning('lead.rate_limit_log_failed', [
                'exception' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }
    }

    private function resolveSubdomain(Request $request): ?string
    {
        $raw = (string) ($request->route('subdomain') ?? explode('.', $request->getHost())[0] ?? '');

        return $raw !== '' ? strtolower($raw) : null;
    }
}
