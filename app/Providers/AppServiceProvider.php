<?php

namespace App\Providers;

use App\Listeners\RecordCacheMetrics;
use App\Listeners\RecordScheduledTaskHeartbeat;
use App\Models\Analytics\LeadSubmission;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Feedback;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Notifications\NotificationEmailPolicy;
use App\Models\Core\Notifications\NotificationEmailPreference;
use App\Models\Core\Notifications\NotificationReceipt;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Customer;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Models\Core\User\UserConfirmationPreference;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Policies\CustomerPolicy;
use App\Policies\EnquiryPolicy;
use App\Policies\FeatureFlagPolicy;
use App\Policies\FeedbackPolicy;
use App\Policies\GdprPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PartnaStaffPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SitePolicy;
use App\Policies\UserSelfPolicy;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\Notifications\Adapters\EmailEnquiryNotificationAdapter;
use App\Services\Notifications\Adapters\InAppEnquiryNotificationAdapter;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        // Singleton so the request-scoped $requestCache memo actually persists
        // across the middleware / controller / nested service calls within a
        // single request. Without this, app(FeatureFlagService::class) resolves
        // a fresh instance on every helper call and the memo is always empty.
        $this->app->singleton(FeatureFlagService::class);

        // Wire up the channel adapters in priority order: in-app first (fast,
        // in-process), email second (async job dispatch). Both adapters are
        // resolved from the container so their own dependencies are injected.
        $this->app->singleton(EnquiryNotificationDispatcher::class, function ($app) {
            return new EnquiryNotificationDispatcher([
                $app->make(InAppEnquiryNotificationAdapter::class),
                $app->make(EmailEnquiryNotificationAdapter::class),
            ]);
        });

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
        Gate::policy(UserConfirmationPreference::class, UserSelfPolicy::class);
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
        Gate::policy(Feedback::class, FeedbackPolicy::class);

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
        // causes VerifySupabaseEmailHookSignature to 503 every delivery,
        // silently breaking all auth email (signup, recovery, magiclink, invite).
        if (app()->isProduction() && empty(config('services.supabase.email_hook_secret'))) {
            throw new \RuntimeException('SUPABASE_EMAIL_HOOK_SECRET must be configured in production (auth email hook fails closed without it).');
        }

        // F6 CFG-4 — Nightwatch enabled without a token attempts an unauthenticated
        // ingest connection on every request/command, generating silent error noise.
        // If telemetry is on in production, the token must be present.
        if (app()->isProduction() && (bool) config('nightwatch.enabled') && empty(config('nightwatch.token'))) {
            throw new \RuntimeException('NIGHTWATCH_TOKEN must be set when NIGHTWATCH_ENABLED is true in production.');
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

        // Scheduler heartbeat — feeds GET /api/health/scheduler so a stopped cron
        // runner becomes visible. See RecordScheduledTaskHeartbeat for rationale.
        Event::listen(ScheduledTaskStarting::class, RecordScheduledTaskHeartbeat::class);

        // Cache hit/miss/write counters — bucketed by key prefix into Redis hashes so
        // AggregateCacheMetricsJob can surface per-prefix hit rates to Nightwatch.
        Event::listen(CacheHit::class, RecordCacheMetrics::class);
        Event::listen(CacheMissed::class, RecordCacheMetrics::class);
        Event::listen(KeyWritten::class, RecordCacheMetrics::class);

        // Strict-mode N+1 trap: throw on unloaded relation access outside production
        // so tests/local catch lazy loading instead of leaking slow queries to prod.
        Model::preventLazyLoading(! app()->isProduction());
    }

    /**
     * Authorize a request to the Horizon dashboard.
     *
     * Behavior:
     * - Non-production: always allowed (dev/staging convenience).
     * - Production with no HORIZON_DASHBOARD credentials: denied → 403.
     * - Production with credentials configured:
     *     - Missing/invalid Basic auth header → 401 + WWW-Authenticate challenge.
     *     - Valid credentials (constant-time compare) → allowed.
     *
     * Extracted as a static method so it can be unit-tested without standing up
     * the full HTTP stack. Called from the Horizon::auth closure in boot().
     */
    public static function authorizeHorizonRequest(Request $request): bool
    {
        if (! app()->isProduction()) {
            return true;
        }

        $expectedUser = (string) config('horizon.dashboard.username', '');
        $expectedPassword = (string) config('horizon.dashboard.password', '');

        // Unset credentials = sealed prod (matches prior behavior).
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

            return Limit::perMinute(60)->by($request->ip());
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
            $key = $request->header('CF-Connecting-IP') ?? $request->ip();

            return Limit::perMinute($perMinute)
                ->by((string) $key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429));
        });

        // Public site endpoints (viewing sites, pages)
        RateLimiter::for('public-site', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests.  Please try again later.',
                    ], 429);
                });
        });

        // Analytics endpoints (pageviews, clicks)
        RateLimiter::for('analytics', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(120)
                ->by($request->ip());
        });

        // Per-link click cap — secondary defense against sustained single-link spam
        RateLimiter::for('analytics-click', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $blockId = $request->input('block_id', 'unknown');

            return Limit::perMinute(5)
                ->by($request->ip().':click:'.$blockId);
        });

        // Customer lead submissions (form submissions)
        RateLimiter::for('leads', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $subdomain = $request->route('subdomain') ?? 'unknown';

            return [
                // Per IP:  3 submissions per minute
                Limit::perMinute(3)
                    ->by($request->ip())
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many submissions. Please wait before trying again.',
                        ], 429);
                    }),

                // Per subdomain: 100 submissions per minute (prevent abuse)
                Limit::perMinute(100)
                    ->by($subdomain)
                    ->response(function () {
                        return response()->json([
                            'message' => 'This site is receiving too many submissions. Please try again later.',
                        ], 429);
                    }),
            ];
        });

        // Public waitlist submissions
        RateLimiter::for('waitlist', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $email = strtolower(trim((string) $request->input('email', '')));
            $emailKey = $email !== '' ? hash('sha256', $email) : 'unknown';

            return [
                Limit::perMinute(5)
                    ->by('waitlist:ip:'.$request->ip())
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many waitlist submissions. Please try again shortly.',
                        ], 429);
                    }),

                Limit::perHour(12)
                    ->by('waitlist:email:'.$emailKey)
                    ->response(function () {
                        return response()->json([
                            'message' => 'This email has been submitted recently. Please try again later.',
                        ], 429);
                    }),
            ];
        });

        // public-subscribe: newsletter signups. Tightened from the previous
        // throttle:public-site (60/min IP) to 5/min IP + 12/h per email,
        // matching the waitlist limiter's per-email cap.
        RateLimiter::for('public-subscribe', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($request->ip())->response(function () {
                    return response()->json(['message' => 'Too many subscription attempts. Please wait before trying again.'], 429);
                }),
                Limit::perHour(12)->by($email !== '' ? "email:{$email}" : 'no-email')->response(function () {
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

            return Limit::perMinute(300)
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
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

            return Limit::perMinute(300)
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429);
                });
        });

        // Webhook endpoints (Square, Fresha, Stripe Connect)
        RateLimiter::for('webhooks', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(200)
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

            return Limit::perMinute(5)
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many account creation attempts. Please try again later.',
                    ], 429);
                });
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

            return Limit::perMinute(10)
                ->by((string) $key)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many session management requests. Please try again later.',
                    ], 429);
                });
        });

    }
}
