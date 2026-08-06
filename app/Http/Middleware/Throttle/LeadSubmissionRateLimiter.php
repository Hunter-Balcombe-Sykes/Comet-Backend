<?php

namespace App\Http\Middleware\Throttle;

use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Models\Analytics\LeadSubmission;
use Illuminate\Http\Request;

/**
 * The `leads` limiter's degraded-mode counter.
 *
 * When Valkey is unreachable, ResilientRateLimiter consults this instead of
 * opening the gate. Rate limiting needs a COUNTER, not Redis specifically —
 * and both controllers behind `throttle:leads` already write one:
 * PublicEnquiryController::logLead() and PublicCustomerLeadController::logLead()
 * insert an analytics.lead_submissions row synchronously on every outcome.
 * The audit trail IS the rate-limit state; there is no second counter to keep
 * in sync.
 *
 * REUSES THE CONTROLLERS' OWN TRAITS ON PURPOSE. hashIp() and
 * resolveSiteSubdomain() must derive byte-identical values to the ones written
 * into the rows, or the counter reads a bucket nobody fills. Do not
 * "simplify" either to CF-Connecting-IP or to route('subdomain'): the limiter
 * closure in AppServiceProvider uses those, but logLead() does not, and this
 * class has to match logLead().
 *
 * Executes ONLY on the store-fault path. Two indexed counts
 * (lead_submissions_ip_time_idx, lead_submissions_subdomain_time_idx) cost
 * nothing at 3/min/IP, and nothing at all while Redis is healthy.
 */
class LeadSubmissionRateLimiter
{
    use HashesClientData;
    use ResolvesSubdomainFromHost;

    /** Rows written by the limiter's OWN rejections. See countSince() below. */
    private const SELF_INFLICTED_OUTCOME = 'rate_limited';

    /**
     * True when this request is over either bucket and must be rejected.
     *
     * Answers ONE combined question rather than per-bucket, because
     * RateLimiter::tooManyAttempts() receives an opaque hashed key and the
     * `leads` limiter returns two Limits — the bucket is not recoverable from
     * the key without coupling to Laravel's key-derivation internals. The cost
     * is cosmetic: the visitor always gets the per-IP 429 wording.
     */
    public function exceeded(Request $request): bool
    {
        $since = now()->subMinute();

        $ipHash = $this->hashIp($request->ip());
        if ($ipHash !== null) {
            $limit = (int) config('partna.throttle.leads_degraded_per_minute_ip', 3);
            // `>= 0`, not `> 0`. A limit of exactly 0 is the natural "stop all
            // lead traffic now" mid-incident clamp the config comment promises —
            // `Limit::perMinute(0)` blocks everything in healthy mode, so this
            // fallback must match. Since countSince() can never return a
            // negative count, `$limit === 0` makes the `>= $limit` comparison
            // always true, i.e. always block, with no special case needed.
            // Negative/garbage config is left bypassing this bucket (as before)
            // rather than reinterpreted as "block everything", which would turn
            // a typo into a silent full outage on a public write path.
            if ($limit >= 0 && $this->countSince('ip_hash', $ipHash, $since) >= $limit) {
                return true;
            }
        }

        $subdomain = $this->resolveSiteSubdomain($request);
        if ($subdomain !== null) {
            $limit = (int) config('partna.throttle.leads_degraded_per_minute_subdomain', 100);
            if ($limit >= 0 && $this->countSince('subdomain', $subdomain, $since) >= $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Carbon comparison, NOT raw `interval '1 minute'` SQL — the Feature suite
     * runs SQLite and would not parse a Postgres interval literal.
     */
    private function countSince(string $column, string $value, \DateTimeInterface $since): int
    {
        return LeadSubmission::query()
            ->where($column, $value)
            ->where('occurred_at', '>', $since)
            // Laravel's ThrottleRequests does not hit() once already over the
            // limit, so a 429 never extends the Redis window. LogLeadRateLimits
            // writes a row for every 429 anyway. Counting those would make this
            // fallback STRICTER than Redis: an over-limit client's own
            // rejections would keep them locked out indefinitely.
            ->where('outcome', '!=', self::SELF_INFLICTED_OUTCOME)
            ->count();
    }
}
