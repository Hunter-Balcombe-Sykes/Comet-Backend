<?php

namespace App\Providers;

use App\Catalog\DetectorSuspensions;
use App\Http\Middleware\Throttle\FailOpenThrottleRequests;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\InstagramActorDriver;
use App\Ingest\Runtime\Effects\MenuActorDriver;
use App\Ingest\Runtime\Effects\MusicActorDriver;
use App\Ingest\Runtime\Effects\PlacesDetailsDriver;
use App\Listeners\BlockSuppressedRecipients;
use App\Listeners\RecordCacheMetrics;
use App\Listeners\RecordScheduledTaskHeartbeat;
use App\Models\Analytics\LeadSubmission;
use App\Models\Content\Collection as ContentCollection;
use App\Models\Content\Item as ContentItem;
use App\Models\Content\Storefront as ContentStorefront;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Feedback;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Notifications\NotificationEmailPolicy;
use App\Models\Core\Notifications\NotificationEmailPreference;
use App\Models\Core\Notifications\NotificationReceipt;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\DesignKitRestyle;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\Page;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Core\Site\Workplace;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Customer;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Policies\CasePolicy;
use App\Policies\ContentCollectionPolicy;
use App\Policies\ContentItemPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DecisionPolicy;
use App\Policies\DesignKitRestylePolicy;
use App\Policies\EarlyAccessSignupPolicy;
use App\Policies\EnquiryPolicy;
use App\Policies\FeatureAvailabilityPolicy;
use App\Policies\FeatureFlagPolicy;
use App\Policies\FeedbackPolicy;
use App\Policies\GdprPolicy;
use App\Policies\IntegrationConnectionPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PartnaStaffPolicy;
use App\Policies\PreAccountBuildPolicy;
use App\Policies\SectionPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SitePolicy;
use App\Policies\UserSegmentPolicy;
use App\Policies\UserSelfPolicy;
use App\Routing\Probes\ProbeBudget;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use App\Services\Analytics\Ingestors\SyncIngestor;
use App\Services\Analytics\Writers\PostgresEventWriter;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\Http\FetchBudget;
use App\Services\Notifications\Adapters\EmailEnquiryNotificationAdapter;
use App\Services\Notifications\Adapters\InAppEnquiryNotificationAdapter;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use App\Services\PreAccount\Notifications\ClaimDmChannel;
use App\Services\PreAccount\Notifications\NullClaimDmChannel;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

// V2: Bootstraps application-wide rate limiters for public, authenticated, webhook, staff, and internal API routes.
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Routing (plan §2). Both are derived from the compiled catalog
        // artefact rather than constructed from dependencies, so the container
        // cannot autowire them — and both are immutable, so one instance per
        // process is right. A deploy swaps the artefact and the process
        // restarts, which is exactly when these should change.
        $this->app->singleton(PublicSuffixList::class, fn () => PublicSuffixList::instance());

        // The one exception to "immutable, derived from the artefact": the
        // staff kill-switch (catalog.detector_suspensions) is runtime state a
        // deploy does not carry. It is folded in HERE, at singleton-build
        // time, rather than read inside LinkProjector::project() — the
        // projector's no-I/O contract is what makes `routing:reproject` a real
        // diff tool, so a suspension has to reach it as data.
        //
        // Consequence worth knowing: the fold happens once per process, so the
        // memo TTL in DetectorSuspensions is not the only staleness window —
        // a long-lived worker holds its pack until it restarts. Acceptable for
        // an operator control on the web tier (fresh container per request);
        // stated here so nobody reads the 60s TTL as the whole story.
        $this->app->singleton(
            Rulepack::class,
            fn ($app) => Rulepack::fromCompiledCatalog()
                ->withSuspensions($app->make(DetectorSuspensions::class)->active()),
        );

        // Ordered, explicit driver list rather than a discovery scan: this decides
        // which (kind, name) pairs may spend money, so it should be a list someone
        // has to edit deliberately. `actor` alone is ambiguous — it is shared by
        // Instagram, the music connectors and the three MENU connectors, which is
        // why the registry matches on BOTH halves of the pair.
        $this->app->singleton(BilledEffectDriverRegistry::class, fn ($app) => new BilledEffectDriverRegistry([
            $app->make(PlacesDetailsDriver::class),
            $app->make(InstagramActorDriver::class),
            $app->make(MusicActorDriver::class),
            $app->make(MenuActorDriver::class),
        ]));

        // Singleton so the request-scoped $requestCache memo actually persists
        // across the middleware / controller / nested service calls within a
        // single request. Without this, app(FeatureFlagService::class) resolves
        // a fresh instance on every helper call and the memo is always empty.
        $this->app->singleton(FeatureFlagService::class);

        // scoped (not singleton): FetchBudget::open() stores the open deadline
        // on $this, so every collaborator that might fetch during one open
        // budget — SafeUrlFetcher AND YoutubeThumbnailResolver, which
        // deliberately bypasses SafeUrlFetcher for its SSRF-free i.ytimg.com
        // probes — must share the SAME instance, or the deadline set by one
        // (e.g. ConnectResolver) silently has zero effect on the other. Without
        // ANY explicit binding, Laravel hands out a fresh instance per
        // resolution (no-arg constructor), which is exactly the trap that let
        // a YouTube connect's thumbnail-probe round run unbounded even after
        // SafeUrlFetcher itself was correctly wired (2026-07-20 W1 independent
        // review). scoped over singleton so Laravel's queue worker
        // (forgetScopedInstances() between jobs) can't leak a deadline from one
        // job into the next.
        $this->app->scoped(FetchBudget::class);

        // scoped for exactly the reason above, and it bit the same way: the
        // per-RUN dimension of ProbeBudget is counted on $this, and LinkProbeWorker
        // and ProbeGate each inject it. Unbound, Laravel hands each collaborator
        // its own instance, so the worker's counter and the gate's counter are
        // different numbers and the per-run cap never fires — an import could
        // probe every link on a 200-link page while the daily caps looked
        // healthy. scoped, not singleton, so forgetScopedInstances() resets the
        // run between queue jobs: one job = one run.
        $this->app->scoped(ProbeBudget::class);

        // scoped so a single RecordCacheMetrics instance accumulates every cache
        // hit/miss/write across one HTTP request (the listener is resolved fresh
        // per event otherwise, and its per-request batch would never build up).
        // scoped over singleton for the same reason as FetchBudget above:
        // forgetScopedInstances() drops it between queue jobs, so a worker can't
        // carry request-batch state — or an unflushed batch — from one job to the next.
        $this->app->scoped(RecordCacheMetrics::class);

        // Wire up the channel adapters in priority order: in-app first (fast,
        // in-process), email second (async job dispatch). Both adapters are
        // resolved from the container so their own dependencies are injected.
        $this->app->singleton(EnquiryNotificationDispatcher::class, function ($app) {
            return new EnquiryNotificationDispatcher([
                $app->make(InAppEnquiryNotificationAdapter::class),
                $app->make(EmailEnquiryNotificationAdapter::class),
            ]);
        });

        // Analytics ingest seams. Writer is fixed (Postgres today); ingestor switches
        // on env/queue connection — inline in local/testing or when queue is sync,
        // queued otherwise. Mirrors MediaUploadService::dispatchImageJob's gate.
        $this->app->singleton(AnalyticsEventWriter::class, PostgresEventWriter::class);
        $this->app->singleton(AnalyticsIngestor::class, function ($app) {
            $inline = in_array($app->environment(), ['local', 'testing'], true)
                || (string) config('queue.default', 'sync') === 'sync';

            return $inline ? $app->make(SyncIngestor::class) : $app->make(QueuedIngestor::class);
        });

        // ClaimDmChannel seam: interface bound to null implementation.
        // The real driver (ManyChat or open-source alternative) will implement
        // this interface later without changing the claim core.
        $this->app->bind(
            ClaimDmChannel::class,
            NullClaimDmChannel::class,
        );

        // A Redis/Valkey outage used to surface as HTTP 500 on every public
        // sitepage read, because ThrottleRequests threw before the request ever
        // reached the DB-backed read that would have served fine. The subclass
        // wraps only the RateLimiter instance IT holds, so allow-listed public
        // limiters fail open and everything else fails closed as a clean 503.
        //
        // bind(), not singleton(): each `throttle:x` pipeline entry must get its
        // own instance so a multi-limiter route evaluates each limiter's mode
        // independently.
        //
        // Bound over ThrottleRequests::class rather than aliased, on purpose —
        // the priority-list pins in bootstrap/app.php match that literal class
        // name, and a rename would silently un-pin VerifySupabaseJwt.
        // Deliberately NOT bound over the Illuminate\Cache\RateLimiter singleton:
        // EscalatesRepeatedFaults' Tier 2 alarm only fires because
        // RateLimiter::hit() throws, so a globally resilient limiter would make
        // the analytics fail-open paths silent.
        $this->app->bind(ThrottleRequests::class, FailOpenThrottleRequests::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Block::class, SitePolicy::class);
        Gate::policy(SiteMedia::class, SitePolicy::class);
        Gate::policy(SiteSubdomainAlias::class, SitePolicy::class);
        Gate::policy(Enquiry::class, EnquiryPolicy::class);
        Gate::policy(LeadSubmission::class, SitePolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(ServiceCategory::class, ServicePolicy::class);
        Gate::policy(User::class, UserSelfPolicy::class);
        Gate::policy(UserDeletionAuditEntry::class, UserSelfPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(NotificationEmailPreference::class, NotificationPolicy::class);
        Gate::policy(NotificationEmailPolicy::class, NotificationPolicy::class);
        Gate::policy(NotificationReceipt::class, NotificationPolicy::class);
        Gate::policy(EmailSubscription::class, NotificationPolicy::class);
        Gate::policy(DataExportAudit::class, GdprPolicy::class);
        Gate::policy(PartnaStaff::class, PartnaStaffPolicy::class);
        Gate::policy(FeatureFlag::class, FeatureFlagPolicy::class);
        Gate::policy(FeatureFlagOverride::class, FeatureFlagPolicy::class);
        Gate::policy(UserSegment::class, UserSegmentPolicy::class);
        Gate::policy(UserSegmentMember::class, UserSegmentPolicy::class);
        Gate::policy(FeatureAvailabilityRule::class, FeatureAvailabilityPolicy::class);
        Gate::policy(EarlyAccessSignup::class, EarlyAccessSignupPolicy::class);
        Gate::policy(Feedback::class, FeedbackPolicy::class);
        Gate::policy(ModerationCase::class, CasePolicy::class);
        Gate::policy(Decision::class, DecisionPolicy::class);
        Gate::policy(IntegrationConnection::class, IntegrationConnectionPolicy::class);
        Gate::policy(PreAccountBuild::class, PreAccountBuildPolicy::class);
        // Menu carries user_id directly — generic owner policy.
        Gate::policy(Menu::class, SitePolicy::class);
        // FOUND-4: workplace card model. Owned via its parent Site so it maps
        // to the parent's policy.
        Gate::policy(Workplace::class, SitePolicy::class);
        // Surface model (plan §7): pages and sections carry site_id, so their
        // policy resolves ownership through a preloaded site relation.
        // section_items/section_groups are authorised via the parent section.
        Gate::policy(Page::class, SectionPolicy::class);
        Gate::policy(Section::class, SectionPolicy::class);
        // Design-kit restyles (plan §13): carry site_id, same ownership shape.
        Gate::policy(DesignKitRestyle::class, DesignKitRestylePolicy::class);
        // Content spine (plan §5/§6): items and the duplicates queue carry
        // user_id directly; manual_overrides go via the parent item.
        Gate::policy(ContentItem::class, ContentItemPolicy::class);
        // Collections + storefronts (Slice 5a §3.1): collections carry user_id
        // directly, storefronts resolve ownership through their parent collection.
        Gate::policy(ContentCollection::class, ContentCollectionPolicy::class);
        Gate::policy(ContentStorefront::class, ContentCollectionPolicy::class);

        // Refuse to boot in production with throttling disabled — a misconfigured
        // PARTNA_THROTTLE_ENABLED=false would silently strip all rate limiting.
        if (app()->isProduction() && ! (bool) config('partna.throttle.enabled', true)) {
            throw new \RuntimeException('PARTNA_THROTTLE_ENABLED must not be false in production.');
        }

        // Refuse to boot in production with an empty public_domain — an unset
        // PARTNA_PUBLIC_DOMAIN would silently break every public-site route by
        // producing a domain pattern of "{subdomain}." that matches nothing.
        if (app()->isProduction() && empty(config('partna.public_domain'))) {
            throw new \RuntimeException('PARTNA_PUBLIC_DOMAIN must be configured in production.');
        }

        // §28.17 SEC-2 — JWKS fail-closed in production.
        // VerifySupabaseJwt falls open (accepts unverified JWTs) when JWKS fetch
        // fails unless `SUPABASE_JWKS_FAIL_CLOSED=true`. Production must opt-in
        // explicitly; refuse to boot if the flag is false.
        if (app()->isProduction() && ! (bool) config('supabase.jwks_fail_closed', true)) {
            throw new \RuntimeException('SUPABASE_JWKS_FAIL_CLOSED must be true in production (auth fails open without it).');
        }

        // #SEC-16 (unified-actions-security) = #SEC-4 (claim-gate) — bot protection
        // that claims to enforce but cannot. VerifyBotToken short-circuits only on
        // mode=off; at mode=enforce it runs the full path and then has no verifier
        // to call, so every wired bot.token route either admits everyone or refuses
        // everyone (depending on fail_open) while the config reads "enforced".
        // Inert on both current environments by construction — production is
        // turnstile/shadow and development is null/off, so neither is enforce+null.
        // It exists to stop a future half-flip (mode raised without a driver).
        // Outside production this warns rather than throws: a local .env mid-edit
        // must not become unbootable.
        // ⚠️ The absent-driver sentinel arrives in THREE shapes and all mean the
        // same thing. .env.example ships `BOT_PROTECTION_DRIVER=null`, and
        // Laravel's Env::get() coerces the literal string "null" to PHP null —
        // so a `=== 'null'` test is false in every environment that sets the
        // documented default, i.e. the guard would be dead in exactly the
        // configuration it exists to catch. Unset gives the config default
        // string 'null'; an empty assignment gives ''. Normalise all three.
        $botDriver = config('partna.bot_protection.driver');
        $botDriverAbsent = $botDriver === null || $botDriver === '' || $botDriver === 'null';

        if (config('partna.bot_protection.mode') === 'enforce' && $botDriverAbsent) {
            $botMisconfig = 'BOT_PROTECTION_MODE=enforce requires a real BOT_PROTECTION_DRIVER (got "null") — nothing would be verified.';

            if (app()->isProduction()) {
                throw new \RuntimeException($botMisconfig);
            }

            Log::warning($botMisconfig);
        }

        // F2 AUTH-1 — JWT issuer/audience must be configured outside local/testing.
        // VerifySupabaseJwt::claimsMatchConfig() fails closed on a blank issuer or
        // audience (a blank value would otherwise let cross-project tokens pass),
        // so an unset SUPABASE_JWT_ISSUER / SUPABASE_JWT_AUD would silently 401
        // every authenticated request. Crash loudly at boot instead.
        if (! app()->environment('local', 'testing')
            && (empty(config('supabase.jwt_issuer')) || empty(config('supabase.jwt_audience')))) {
            throw new \RuntimeException('SUPABASE_JWT_ISSUER and SUPABASE_JWT_AUD must be configured (JWT auth fails closed without them).');
        }

        // Supabase email hook secret must be set in production. An empty value
        // causes VerifySupabaseHookSignature to 503 every delivery,
        // silently breaking all auth email (signup, recovery, magiclink, invite).
        if (app()->isProduction() && empty(config('services.supabase.email_hook_secret'))) {
            throw new \RuntimeException('SUPABASE_EMAIL_HOOK_SECRET must be configured in production (auth email hook fails closed without it).');
        }

        // Supabase auth hook secret must be set in production. An empty value
        // causes VerifySupabaseHookSignature to 503 every MFA verify attempt,
        // blocking all AAL2 promotion.
        if (app()->isProduction() && empty(config('services.supabase.auth_hook_secret'))) {
            throw new \RuntimeException('SUPABASE_AUTH_HOOK_SECRET must be configured in production (MFA verification hook fails closed without it).');
        }

        // F6 CFG-4 — Nightwatch enabled without a token attempts an unauthenticated
        // ingest connection on every request/command, generating silent error noise.
        // If telemetry is on in production, the token must be present.
        if (app()->isProduction() && (bool) config('nightwatch.enabled') && empty(config('nightwatch.token'))) {
            throw new \RuntimeException('NIGHTWATCH_TOKEN must be set when NIGHTWATCH_ENABLED is true in production.');
        }

        // APP_DEBUG=true in production leaks raw exception text, file paths, and
        // stack traces via the exception renderer's debug branch (#P3-02).
        if (app()->isProduction() && config('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false in production.');
        }

        // FEEDBACK_IP_HASH_PEPPER must be set in production. An empty pepper
        // means all ip_hash values are stored NULL — abuse correlation becomes
        // impossible and nobody notices until an incident requires it.
        if (app()->isProduction() && empty(config('partna.feedback.ip_hash_pepper'))) {
            throw new \RuntimeException('FEEDBACK_IP_HASH_PEPPER must be configured in production (ip_hash disabled without it).');
        }

        // Auth::user() is always null in this app (Supabase JWT), so a user-based
        // Horizon gate is not possible. Default behavior: dashboard is open in
        // non-production environments and sealed in production. Production access
        // can be selectively enabled by setting HORIZON_DASHBOARD_USERNAME and
        // HORIZON_DASHBOARD_PASSWORD — the gate then requires HTTP Basic auth.
        // Nightwatch remains the primary prod queue-monitoring surface.
        Horizon::auth(fn (Request $request) => static::authorizeHorizonRequest($request));

        // Long-wait notification routing. Thresholds live in config/horizon.php
        // 'waits'. Mail/Slack are independent — set either or both. These are
        // *queue backlog* alerts (jobs sitting too long), distinct from Nightwatch
        // which covers job *exceptions*.
        if (($mail = config('horizon.notifications.mail')) !== null && $mail !== '') {
            Horizon::routeMailNotificationsTo($mail);
        }

        if (($slack = config('horizon.notifications.slack_webhook')) !== null && $slack !== '') {
            Horizon::routeSlackNotificationsTo($slack, config('horizon.notifications.slack_channel'));
        }

        $this->configureRateLimiting();
        $this->configureQueueRateLimiting();

        // Scheduler heartbeat — feeds GET /api/health/scheduler so a stopped cron
        // runner becomes visible. See RecordScheduledTaskHeartbeat for rationale.
        Event::listen(ScheduledTaskStarting::class, RecordScheduledTaskHeartbeat::class);

        // Cache hit/miss/write counters — bucketed by key prefix into Redis hashes so
        // AggregateCacheMetricsJob can surface per-prefix hit rates to Nightwatch.
        Event::listen(CacheHit::class, RecordCacheMetrics::class);
        Event::listen(CacheMissed::class, RecordCacheMetrics::class);
        Event::listen(KeyWritten::class, RecordCacheMetrics::class);

        // Send-time suppression gate — cancels any outbound mail to an address on
        // core.email_suppressions (hard bounce / spam complaint from Resend). The
        // one chokepoint that makes suppression bite for OTP, claim-invite, and
        // transactional mail alike. Fails open on lookup error (see the listener).
        Event::listen(MessageSending::class, BlockSuppressedRecipients::class);

        // Strict-mode N+1 trap: throw on unloaded relation access outside production
        // so tests/local catch lazy loading instead of leaking slow queries to prod.
        Model::preventLazyLoading(! app()->isProduction());
    }

    /**
     * Authorize a request to the Horizon dashboard.
     *
     * Behavior:
     * - local / testing: always allowed (genuinely private envs).
     * - Any deployed env (development, production) with no HORIZON_DASHBOARD
     *   credentials: denied → 403. dev-api.partna.au is publicly reachable and
     *   Horizon renders live job payloads, so "non-prod" is not a safe bypass.
     * - Deployed env with credentials configured:
     *     - Missing/invalid Basic auth header → 401 + WWW-Authenticate challenge.
     *     - Valid credentials (constant-time compare) → allowed.
     *
     * Extracted as a static method so it can be unit-tested without standing up
     * the full HTTP stack. Called from the Horizon::auth closure in boot().
     */
    public static function authorizeHorizonRequest(Request $request): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        $expectedUser = (string) config('horizon.dashboard.username', '');
        $expectedPassword = (string) config('horizon.dashboard.password', '');

        // Unset credentials = sealed (matches prior prod behavior).
        if ($expectedUser === '' || $expectedPassword === '') {
            return false;
        }

        $providedUser = (string) ($request->getUser() ?? '');
        $providedPassword = (string) ($request->getPassword() ?? '');

        $valid = $providedUser !== ''
            && $providedPassword !== ''
            && hash_equals($expectedUser, $providedUser)
            && hash_equals($expectedPassword, $providedPassword);

        if (! $valid) {
            // Send 401 + challenge so the browser shows the Basic auth prompt.
            // Returning false would yield 403 with no challenge → user is stuck.
            abort(401, 'Authentication required', ['WWW-Authenticate' => 'Basic realm="Horizon"']);
        }

        return true;
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        $throttleEnabled = (bool) config('partna.throttle.enabled', true);

        // Health-check and ping endpoints
        RateLimiter::for('health-check', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute((int) config('partna.throttle.health_check_per_minute', 60))
                ->by($request->ip());
        });

        // Individual public profile endpoint (§28.8). Tunable via config — pre-clamped
        // to >=1 so a misconfigured 0 doesn't lock out the Astro Worker subrequest path.
        // Key prefers CF-Connecting-IP over $request->ip() so a TrustProxies misconfig
        // can't collapse all edge requests onto one bucket.
        RateLimiter::for('public-profile', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $perMinute = max(1, (int) config('partna.public_profile.rate_limit_per_minute', 60));
            $ip = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

            // The Astro renderer calls this route SERVER-SIDE, so $ip is the
            // renderer's Cloudflare egress, not the visitor's — keyed on IP alone
            // every sitepage shares one bucket and the ceiling scales with how many
            // sitepages exist. Bucket per handle instead. The IP stays in the key:
            // handle ALONE would collapse every Cloudflare location's revalidation
            // for a viral page into one bucket, which approaches this same limit.
            $handle = (string) $request->route('handle');

            return Limit::perMinute($perMinute)
                ->by($handle.'|'.$ip)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429));
        });

        // Public site endpoints (viewing sites, pages). CF-Connecting-IP
        // preferred over $request->ip() (SEC-2) — matches public-profile /
        // signup-availability so a TrustProxies misconfig can't collapse all
        // edge requests onto one bucket.
        RateLimiter::for('public-site', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $key = $request->header('CF-Connecting-IP') ?? $request->ip();

            return Limit::perMinute((int) config('partna.throttle.public_site_per_minute', 60))
                ->by((string) $key)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests.  Please try again later.',
                    ], 429);
                });
        });

        // Signup availability endpoint (P2-44 + P3-10). Dedicated bucket — tighter than the
        // shared public-site limiter (60/min) so heavy signup-form scanning can't starve other
        // public routes. Returns an array (ANDed) so BOTH limits must pass before the request
        // proceeds. CF-Connecting-IP preferred over $request->ip() so the real client IP is
        // keyed behind Cloudflare, not the edge node's IP.
        RateLimiter::for('signup-availability', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $key = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

            return [
                // Per-minute bucket — dedicated (not shared with other public routes).
                Limit::perMinute(config('partna.throttle.signup_availability_per_minute', 10))
                    ->by($key)
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),

                // Per-hour secondary gate (P3-10) — caps slow enumeration that stays
                // under the per-minute window over a sustained period.
                Limit::perHour(config('partna.throttle.signup_availability_per_hour', 60))
                    ->by($key)
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            ];
        });

        // Live subdomain availability checks (authenticated URL-change flow).
        // Keyed per user (supabase_uid) so one user's typing can't starve
        // others behind a shared IP; IP fallback covers edge cases where the
        // attribute isn't resolved yet.
        RateLimiter::for('subdomain-availability', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $key = (string) ($request->attributes->get('supabase_uid')
                ?? $request->header('CF-Connecting-IP')
                ?? $request->ip());

            return Limit::perMinute(config('partna.throttle.subdomain_availability_authed_per_minute', 30))
                ->by('subdomain-availability:'.$key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429));
        });

        // Login resolve-identifier endpoint (P2-44). Dedicated bucket — tighter than
        // public-site (60/min), mirrors expected mistyped-handle retries (20/min).
        // Uses CF-Connecting-IP for the same reason as signup-availability.
        RateLimiter::for('login-identifier', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $key = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

            return Limit::perMinute(config('partna.throttle.login_identifier_per_minute', 20))
                ->by($key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429));
        });

        // Analytics endpoints (pageviews, clicks). Two limits since
        // 2026-08-27 (plan-01 critic find): beacons arrive via the pages
        // Worker's /t/* proxy, and the subrequest's own CF-Connecting-IP is
        // often a shared Cloudflare colo IP (observed live) — keying only on
        // it starved one bucket across many real visitors. The proxy now
        // forwards the ORIGINAL request's connecting IP as x-visitor-ip
        // (pass-through, never synthesized), which keys the fine-grained
        // per-visitor limit; the true connecting IP keeps a higher-ceiling
        // flood backstop, so a direct caller spoofing x-visitor-ip to
        // rotate buckets still runs into the real-IP ceiling.
        RateLimiter::for('analytics', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $trueIp = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());
            $visitorIp = (string) ($request->header('x-visitor-ip') ?? $trueIp);

            return [
                Limit::perMinute((int) config('partna.throttle.analytics_per_minute', 120))
                    ->by('v:'.$visitorIp),
                Limit::perMinute((int) config('partna.throttle.analytics_ip_backstop_per_minute', 3000))
                    ->by('ip:'.$trueIp),
            ];
        });

        // Per-link click cap — secondary defense against sustained single-link
        // spam. Same visitor-ip/true-ip split as 'analytics' above: at 5/min
        // keyed on a shared colo IP, TWO visitors clicking the same link
        // through one colo would have starved each other.
        RateLimiter::for('analytics-click', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $blockId = $request->input('block_id', 'unknown');
            $trueIp = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());
            $visitorIp = (string) ($request->header('x-visitor-ip') ?? $trueIp);

            return [
                Limit::perMinute((int) config('partna.throttle.analytics_click_per_minute', 5))
                    ->by('v:'.$visitorIp.':click:'.$blockId),
                Limit::perMinute((int) config('partna.throttle.analytics_click_ip_backstop_per_minute', 120))
                    ->by('ip:'.$trueIp.':click:'.$blockId),
            ];
        });

        // Customer lead submissions (form submissions). CF-Connecting-IP
        // preferred on the IP-keyed bucket (SEC-2) — same rationale as
        // public-site.
        RateLimiter::for('leads', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $subdomain = $request->route('subdomain') ?? 'unknown';
            $key = $request->header('CF-Connecting-IP') ?? $request->ip();

            return [
                // Per IP:  3 submissions per minute
                Limit::perMinute((int) config('partna.throttle.leads_per_minute_ip', 3))
                    ->by((string) $key)
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many submissions. Please wait before trying again.',
                        ], 429);
                    }),

                // Per subdomain: 100 submissions per minute (prevent abuse)
                Limit::perMinute((int) config('partna.throttle.leads_per_minute_subdomain', 100))
                    ->by($subdomain)
                    ->response(function () {
                        return response()->json([
                            'message' => 'This site is receiving too many submissions. Please try again later.',
                        ], 429);
                    }),
            ];
        });

        // Pre-account build creation (site-first signup + staff marketing
        // builds). CF-Connecting-IP preferred (SEC-2) — same rationale as
        // public-site. Scraping is expensive (Apify-billed): tight per-minute
        // + hourly ceiling, both keyed off the same IP so a burst can't
        // exhaust one bucket while leaving the other untouched.
        RateLimiter::for('pre-account-build', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $key = $request->header('CF-Connecting-IP') ?: $request->ip();

            return [
                Limit::perMinute((int) config('partna.throttle.pre_account_build_per_minute', 3))
                    ->by('pab:m:'.$key)
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),

                Limit::perHour((int) config('partna.throttle.pre_account_build_per_hour', 10))
                    ->by('pab:h:'.$key)
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            ];
        });

        // ManyChat build webhook. Each call can trigger an Apify-billed scrape,
        // so this is a spend guard as much as an abuse guard.
        //
        // TWO buckets on purpose, and BOTH are always evaluated — Laravel's
        // ThrottleRequests checks every limit in the returned array, not just
        // the first to trip. The shared 'manychat:h' key is a global spend
        // ceiling, not DoS protection: many distinct IPs each staying under
        // the per-minute cap can still drain it. What the per-IP bucket
        // narrows is a SINGLE abusive caller — which matters because throttle
        // middleware runs BEFORE the secret check, so without it a constant
        // key would let any stranger who knows the URL hammer the endpoint
        // from one IP at no extra cost to them.
        RateLimiter::for('manychat-build', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $ip = $request->header('CF-Connecting-IP') ?: $request->ip();

            return [
                Limit::perMinute((int) config('partna.throttle.manychat_build_per_minute', 10))
                    ->by('manychat:ip:'.$ip)
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),

                Limit::perHour((int) config('partna.throttle.manychat_build_per_hour', 120))
                    ->by('manychat:h')
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            ];
        });

        // Public early-access signups (OV-A). Same posture as the retired waitlist limiter,
        // plus a per-IP daily cap (SEC-1): 5/min + 20/day per IP (CF-Connecting-IP
        // preferred) + 12/h per email. A bot-token bootstrap isn't available here — the
        // marketing site posts cross-origin with no token round-trip — so the daily cap
        // is the server-side backstop against a sustained sub-5/min script grinding all day.
        RateLimiter::for('early-access', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $email = strtolower(trim((string) $request->input('email', '')));
            $emailKey = $email !== '' ? hash('sha256', $email) : 'unknown';
            $key = $request->header('CF-Connecting-IP') ?? $request->ip();

            return [
                Limit::perMinute((int) config('partna.throttle.early_access_per_minute', 5))
                    ->by('early-access:ip:'.$key)
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many submissions. Please try again shortly.',
                        ], 429);
                    }),

                Limit::perDay((int) config('partna.throttle.early_access_per_day', 20))
                    ->by('early-access:ip:day:'.$key)
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many submissions from this network today. Please try again tomorrow.',
                        ], 429);
                    }),

                Limit::perHour((int) config('partna.throttle.early_access_per_hour_email', 12))
                    ->by('early-access:email:'.$emailKey)
                    ->response(function () {
                        return response()->json([
                            'message' => 'This email has been submitted recently. Please try again later.',
                        ], 429);
                    }),
            ];
        });

        // public-subscribe: newsletter signups. Tightened from the previous
        // throttle:public-site (60/min IP) to 5/min IP + 12/h per email,
        // matching the early-access limiter's per-email cap. CF-Connecting-IP
        // preferred on the IP-keyed bucket (SEC-2) — same rationale as
        // public-site.
        RateLimiter::for('public-subscribe', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $email = strtolower((string) $request->input('email', ''));
            $key = $request->header('CF-Connecting-IP') ?? $request->ip();

            return [
                Limit::perMinute((int) config('partna.throttle.public_subscribe_per_minute', 5))->by((string) $key)->response(function () {
                    return response()->json(['message' => 'Too many subscription attempts. Please wait before trying again.'], 429);
                }),
                Limit::perHour((int) config('partna.throttle.public_subscribe_per_hour_email', 12))->by($email !== '' ? "email:{$email}" : 'no-email')->response(function () {
                    return response()->json(['message' => 'Too many subscription attempts for this email. Please try later.'], 429);
                }),
            ];
        });

        // Authenticated professional routes
        RateLimiter::for('authenticated', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on authenticated route — JWT middleware not applied');

            return Limit::perMinute((int) config('partna.throttle.authenticated_per_minute', 300))
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429);
                });
        });

        // Manual menu scans (POST /platforms/menu/scan). Each request bills
        // Mistral OCR + DeepSeek — per-user daily cap on top of the shared
        // AiSpendBudget global caps. Keyed like `authenticated`: supabase_uid,
        // set by the user.api JWT middleware that runs before throttle.
        RateLimiter::for('menu-scan', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on menu-scan route — JWT middleware not applied');

            return Limit::perDay((int) config('partna.throttle.menu_scan_per_day', 15))
                ->by('menu-scan|'.$uid)
                ->response(function () {
                    return response()->json([
                        'message' => "You've reached today's scan limit. Try again tomorrow.",
                    ], 429);
                });
        });

        // User-submitted feedback. Two limits combined: tight per-hour to stop
        // floods, looser per-day to keep a determined user from drowning the
        // notify_emails inbox. Per-user (supabase_uid) keyed.
        RateLimiter::for('feedback-submit', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on feedback route — JWT middleware not applied');

            $response = function () {
                return response()->json([
                    'message' => 'You have submitted a lot of feedback recently. Please try again later.',
                ], 429);
            };

            return [
                Limit::perHour((int) config('partna.feedback.rate_limit_per_hour', 10))
                    ->by('feedback-submit:hour:'.$uid)
                    ->response($response),
                Limit::perDay((int) config('partna.feedback.rate_limit_per_day', 30))
                    ->by('feedback-submit:day:'.$uid)
                    ->response($response),
            ];
        });

        // Staff panel routes
        RateLimiter::for('staff', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on staff route — JWT middleware not applied');

            return Limit::perMinute((int) config('partna.throttle.staff_per_minute', 300))
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429);
                });
        });

        // Webhook endpoints (Square, Fresha)
        RateLimiter::for('webhooks', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute((int) config('partna.throttle.webhooks_per_minute', 200))
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many webhook requests.',
                    ], 429);
                });
        });

        // Account bootstrap (creation)
        RateLimiter::for('bootstrap', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on bootstrap route — JWT middleware not applied');

            return Limit::perMinute((int) config('partna.throttle.bootstrap_per_minute', 5))
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many account creation attempts. Please try again later.',
                    ], 429);
                });
        });

        // Site claim (first-come binding of a Supabase auth user to an
        // unclaimed pre-account site — see ClaimSiteService). Same per-uid
        // shape as bootstrap; the limiter fails loudly if supabase.jwt didn't
        // run first, rather than silently keying on an empty string.
        RateLimiter::for('claim', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = (string) $request->attributes->get('supabase_uid');
            if ($uid === '') {
                throw new \RuntimeException('claim limiter requires supabase_uid — check middleware order.');
            }

            return Limit::perMinute((int) config('partna.throttle.claim_per_minute', 5))->by('claim:'.$uid);
        });

        // Session-management write endpoints (logout, logout-others, revoke session).
        // Keyed per-user because these write to the Redis session-id blocklist
        // (see TokenRevocationService), which is consulted on every authenticated
        // request site-wide — a flood here is a site-wide Redis DoS vector. IP
        // keying is ineffective under corporate NAT; supabase_uid is always
        // present on these authenticated routes.
        RateLimiter::for('session-writes', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            // Fall back to IP only if uid is somehow absent (should never happen —
            // these routes sit behind VerifySupabaseJwt).
            $key = $request->attributes->get('supabase_uid') ?? $request->ip();

            return Limit::perMinute((int) config('partna.throttle.session_writes_per_minute', 10))
                ->by((string) $key)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many session management requests. Please try again later.',
                    ], 429);
                });
        });

        // Public moderation report submissions. CF-Connecting-IP preferred
        // (SCALE-3) — same rationale as the other public limiters above.
        RateLimiter::for('partna.moderation.report', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $cfg = config('partna.moderation.reporting.public_throttle', ['requests' => 5, 'minutes' => 1]);
            $key = $request->header('CF-Connecting-IP') ?? $request->ip();

            return Limit::perMinutes($cfg['minutes'], $cfg['requests'])
                ->by($key)
                ->response(function () {
                    return response()->json([
                        'error' => 'RATE_LIMITED',
                        'message' => 'Hold on a sec, try again in a minute.',
                    ], 429);
                });
        });

        // Per-document download cap — prevents a single IP from hammering one
        // document's presigned-URL generation endpoint. Keyed by IP + document UUID
        // so the bucket is per-document, not shared across all downloads.
        // CF-Connecting-IP preferred (SCALE-2) — same rationale as the other
        // public limiters above.
        RateLimiter::for('document-download', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $documentId = $request->route('document') ?? 'unknown';
            $key = $request->header('CF-Connecting-IP') ?? $request->ip();

            return Limit::perHour((int) config('partna.throttle.document_download_per_hour', 10))
                ->by($key.':doc:'.$documentId)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many download attempts. Please try again later.',
                    ], 429);
                });
        });

    }

    /**
     * Queue (not HTTP) rate limiters. Kept separate from configureRateLimiting()
     * above (HTTP-request limiters keyed by IP/route) and from
     * PlatformRegistryServiceProvider::boot() (platform-scoped connect/refresh
     * limiters) — a mail-provider throughput cap belongs in neither.
     */
    protected function configureQueueRateLimiting(): void
    {
        // R3-SCALE-2: shared per-team Resend budget for SendStaffBroadcastEmailToSubscriberJob
        // (RateLimited('mail-broadcast') middleware). 5/s — HALF of Resend's documented
        // 10 req/s per-team cap, deliberately: that budget is shared with every
        // transactional send in the app, which must never be starved by a broadcast.
        // Cache-backed → Redis in prod → global across ALL workers, unlike a per-job
        // stagger delay (which can't see concurrent transactional traffic spending the
        // same budget). See config/partna.php 'throttle.mail_broadcast_per_second'.
        RateLimiter::for('mail-broadcast', function () {
            return Limit::perSecond(
                (int) config('partna.throttle.mail_broadcast_per_second', 5)
            )->by('mail-broadcast');
        });
    }
}
