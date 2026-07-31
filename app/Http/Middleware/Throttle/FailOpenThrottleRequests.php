<?php

namespace App\Http\Middleware\Throttle;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * ThrottleRequests that degrades instead of 500-ing when its backing store dies.
 *
 * The 2026-07-31 Redis-down drill found that a Valkey outage returned HTTP 500
 * on EVERY public sitepage read and every analytics beacon — the limiter threw
 * a raw RedisException before the request ever reached a DB-backed read that
 * would have worked fine. This subclass wraps ONLY the RateLimiter instance the
 * middleware holds (see ResilientRateLimiter) and picks a failure mode per
 * named limiter.
 *
 * BOUND OVER ThrottleRequests::class, NOT REGISTERED AS A NEW ALIAS. bootstrap/app.php
 * pins VerifySupabaseJwt and IdempotencyKey ahead of the literal string
 * ThrottleRequests::class in the middleware priority list. A new alias would
 * leave those pins matching a class no longer in the route stack, silently
 * un-pinning both — and an unpinned VerifySupabaseJwt means the per-uid
 * limiters fire before `supabase_uid` is set and throw RuntimeException.
 * Binding the interface name keeps the route-stack entry byte-identical, so
 * SortedMiddleware still matches. DeadCacheStoreTest asserts this directly.
 *
 * WHY NOT WRAP handle()/handleRequest() IN A try/catch. addHeaders() runs AFTER
 * $next($request) (ThrottleRequests::handleRequest()), so a catch at that level
 * would re-enter $next and DOUBLE-EXECUTE the controller — and it would also
 * swallow a RedisException thrown by the controller itself, turning a genuine
 * downstream failure into a silent retry. The constructor seam gets the same
 * result with no duplicated framework internals.
 */
class FailOpenThrottleRequests extends ThrottleRequests
{
    /**
     * Limiters that open when the store is unreachable. Everything else — named
     * or inline `throttle:N,M` — fails CLOSED, as a clean 503 rather than a
     * leaked 500. Closed is the default; opening is explicit opt-in.
     *
     *   public-site, public-profile  DB-backed public reads. Fail-open is the
     *                                entire point: a cache outage must not take
     *                                every sitepage offline.
     *   analytics, analytics-click   Restores the documented beacon fail-open
     *                                contract (the ingestor already catches to 2xx).
     *   health-check                 A liveness probe that 503s during a cache
     *                                outage tells the platform to recycle a
     *                                process that is fine.
     *
     * Deliberately absent, and why:
     *   leads, public-subscribe      Public WRITE forms. Unmetered during an
     *                                outage means spam straight into
     *                                site.customers / enquiry mail. A visitor
     *                                retrying a 503 is the better outcome.
     *   bootstrap, claim,            Enumeration and account-claim surfaces —
     *   login-identifier,            the limiters actually worth an attacker's
     *   signup-availability,         effort. They stay shut.
     *   early-access
     *   everything else              Authenticated/staff/webhook surfaces. The
     *                                dashboard is already unusable during a
     *                                cache outage; a 503 is the honest answer.
     *
     * THE TRADE, STATED PLAINLY: opening a limiter under store failure means
     * anyone who can kill the store can disable that limiter. Two things bound
     * it. (1) Valkey is a managed internal service with no attacker-reachable
     * surface — the realistic scenario is an incident, not an attack primitive.
     * (2) The five opened limiters guard idempotent reads and a beacon that
     * already fail-opens by design, so the abuse ceiling is DB read load, not
     * data or account compromise. If that reasoning ever stops holding, it will
     * be because Redis became attacker-reachable — THAT, not this list, is the
     * thing to re-examine. This is a security decision: keep it reviewed, do
     * not accrete into it.
     *
     * @var list<string>
     */
    private const FAIL_OPEN_LIMITERS = [
        'public-site',
        'public-profile',
        'analytics',
        'analytics-click',
        'health-check',
    ];

    private readonly ResilientRateLimiter $resilient;

    public function __construct(RateLimiter $limiter)
    {
        $this->resilient = new ResilientRateLimiter($limiter);

        parent::__construct($this->resilient);
    }

    /**
     * Inline `throttle:60,1` usages never reach handleRequestUsingNamedLimiter,
     * so pin the closed default here rather than relying on the property
     * initialiser — the mode must be a decision taken on every request, not a
     * leftover from whatever ran last.
     *
     * {@inheritDoc}
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        $this->resilient->useFailOpen(false, 'inline');

        // Forward EXACTLY the arguments we were handed. ThrottleRequests::handle()
        // gates its named-limiter branch on `func_num_args() === 3`, so passing
        // the declared parameters through (which pads the call to five with their
        // defaults) silently routes every `throttle:public-site` down the numeric
        // path and blows up as MissingRateLimiterException.
        return parent::handle(...func_get_args());
    }

    /**
     * Multi-limiter routes resolve STRICTEST-WINS for free, and that is
     * intended. /api/public/documents/{id}/download carries
     * ['throttle:public-site', 'throttle:document-download'] as two separate
     * pipeline entries, so each gets its own middleware instance: public-site
     * opens and lets the request through, document-download then closes and
     * 503s it. DeadCacheStoreTest pins this so nobody "fixes" it later.
     *
     * {@inheritDoc}
     */
    protected function handleRequestUsingNamedLimiter($request, Closure $next, $limiterName, Closure $limiter)
    {
        $name = (string) $limiterName;

        $this->resilient->useFailOpen(in_array($name, self::FAIL_OPEN_LIMITERS, true), $name);

        return parent::handleRequestUsingNamedLimiter($request, $next, $limiterName, $limiter);
    }
}
