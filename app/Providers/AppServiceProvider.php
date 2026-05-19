<?php

namespace App\Providers;

use App\Listeners\RecordCacheMetrics;
use App\Listeners\RecordScheduledTaskHeartbeat;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Policies\IntegrationPolicy;
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
        $this->app->singleton(\App\Services\FeatureFlags\FeatureFlagService::class);

        $this->app->singleton(\App\Services\Shopify\Client\ShopifyAdminClient::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyBudgetTracker::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyCostTracker::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyMetrics::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyBulkOperationLock::class);

        // Storefront client needs its own budget bucket — Shopify gives Admin
        // and Storefront separate budgets at their edge, so the local-bucket
        // state must not collide. The cost tracker is shared (sha1 hashes
        // namespace samples by content).
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyStorefrontClient::class, function ($app) {
            return new \App\Services\Shopify\Client\ShopifyStorefrontClient(
                budget: new \App\Services\Shopify\Client\ShopifyBudgetTracker('shopify:storefront-bucket'),
                cost: $app->make(\App\Services\Shopify\Client\ShopifyCostTracker::class),
                metrics: $app->make(\App\Services\Shopify\Client\ShopifyMetrics::class),
            );
        });

        // Stripe SDK client — services that DI \Stripe\StripeClient (e.g.
        // StripeTransactionFetcher, StripeBalanceService, StripeRowGenerator) need
        // the API key configured on the instance the container hands them. Without
        // this binding, every Stripe call from those services raises
        // AuthenticationException which their try/catch silently swallows,
        // surfacing as empty transactions / zero balance on the dashboard.
        // stripe_version is pinned via partna.exports.commission.stripe_api_version
        // (env STRIPE_API_VERSION) so all SDK usage — including the async export
        // pipeline — resolves the same Stripe response shape.
        $this->app->singleton(\Stripe\StripeClient::class, function () {
            return new \Stripe\StripeClient(array_filter([
                'api_key' => config('services.stripe.secret_key'),
                'stripe_version' => config('partna.exports.commission.stripe_api_version'),
            ]));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ProfessionalIntegration::class, IntegrationPolicy::class);
        Gate::policy(\App\Models\Core\Professional\Customer::class, \App\Policies\CustomerPolicy::class);
        Gate::policy(\App\Models\Core\Site\Site::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\Block::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\SiteMedia::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\SiteSubdomainAlias::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\Enquiry::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Analytics\LeadSubmission::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Commerce\CommissionPayout::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\CommissionMovement::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\Order::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\OrderItem::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\BrandAffiliateRollup::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\CommissionExportAudit::class, \App\Policies\CommissionExportAuditPolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandPartnerLink::class, \App\Policies\BrandPartnerLinkPolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandPartnerLinkEvent::class, \App\Policies\BrandPartnerLinkPolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandAffiliateInvite::class, \App\Policies\BrandPartnerLinkPolicy::class);
        Gate::policy(\App\Models\Core\Professional\Service::class, \App\Policies\ServicePolicy::class);
        Gate::policy(\App\Models\Core\Professional\ServiceCategory::class, \App\Policies\ServicePolicy::class);
        Gate::policy(\App\Models\Brand\BrandStoreSettings::class, \App\Policies\BrandResourcePolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandProfile::class, \App\Policies\BrandResourcePolicy::class);
        Gate::policy(\App\Models\Brand\BrandTeamMembership::class, \App\Policies\BrandResourcePolicy::class);
        Gate::policy(\App\Models\Core\Professional\Professional::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Core\Professional\ProfessionalConfirmationPreference::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Core\Professional\WalletCurrencySwitchAudit::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Core\Professional\ProfessionalDeletionAuditEntry::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Billing\Subscription::class, \App\Policies\SubscriptionPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\Notification::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\NotificationEmailPreference::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\NotificationEmailPolicy::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\NotificationReceipt::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\EmailSubscription::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Gdpr\GdprRequest::class, \App\Policies\GdprPolicy::class);
        Gate::policy(\App\Models\Core\Gdpr\DataExportAudit::class, \App\Policies\GdprPolicy::class);
        Gate::policy(\App\Models\Commerce\AffiliateProductSelection::class, \App\Policies\AffiliateProductPolicy::class);
        Gate::policy(\App\Models\Core\Staff\PartnaStaff::class, \App\Policies\PartnaStaffPolicy::class);
        Gate::policy(\App\Models\Core\FeatureFlag::class, \App\Policies\FeatureFlagPolicy::class);
        Gate::policy(\App\Models\Core\FeatureFlagOverride::class, \App\Policies\FeatureFlagPolicy::class);

        // Stripe payment-method abilities — bypasses model-class dispatch so
        // CommissionPolicy methods are reachable without overriding the Professional→
        // ProfessionalSelfPolicy registration. The $brand arg is always the acting
        // professional (self-operation), so $actor->id === $brand->id is the primary guard.
        Gate::define('managePaymentMethod', fn (\App\Models\Core\Professional\Professional $actor, \App\Models\Core\Professional\Professional $brand) => app(\App\Policies\CommissionPolicy::class)->managePaymentMethod($actor, $brand));
        Gate::define('manageWallet', fn (\App\Models\Core\Professional\Professional $actor, \App\Models\Core\Professional\Professional $brand) => app(\App\Policies\CommissionPolicy::class)->manageWallet($actor, $brand));
        Gate::define('startConnect', fn (\App\Models\Core\Professional\Professional $actor, \App\Models\Core\Professional\Professional $pro) => app(\App\Policies\CommissionPolicy::class)->startConnect($actor, $pro));

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

        // Refuse to boot outside tests with a missing Shopify API version — a blank
        // SHOPIFY_API_VERSION would silently fall back to a stale/deprecated version.
        if (! app()->environment('testing') && empty(config('services.shopify.api_version'))) {
            throw new \RuntimeException('SHOPIFY_API_VERSION must be set (e.g. 2026-04).');
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

        // Booking checkout — tighter limit because checkout hits Stripe/Square synchronously
        RateLimiter::for('booking-checkout', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many booking attempts. Please try again shortly.',
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

        // Shopify webhook endpoints (keyed by shop domain, fallback to IP)
        RateLimiter::for('shopify-webhooks', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $key = strtolower(trim((string) $request->header('x-shopify-shop-domain', '')));
            if ($key === '') {
                $key = $request->ip();
            }

            return Limit::perMinute(120)
                ->by($key)
                ->response(function () {
                    return response()->json(['message' => 'Too many webhook requests.'], 429);
                });
        });

        // Affiliate selection write operations (create, delete, reorder)
        RateLimiter::for('affiliate-writes', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on affiliate-writes route — JWT middleware not applied');

            return Limit::perMinute(60)
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many selection changes. Please try again later.',
                    ], 429);
                });
        });

        // Brand catalog write operations (metafield updates, collection management)
        RateLimiter::for('brand-catalog-writes', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on brand-catalog-writes route — JWT middleware not applied');

            return Limit::perMinute(30)
                ->by($uid)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many catalog changes. Please try again later.',
                    ], 429);
                });
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

        // Internal Hydrogen endpoints (server-to-server)
        RateLimiter::for('hydrogen-internal', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(120)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests.',
                    ], 429);
                });
        });

        // Embedded Shopify app — keyed by the resolved shop domain from the
        // JWT (`dest` claim). VerifyShopifySessionToken is pinned ahead of
        // ThrottleRequests in bootstrap/app.php so the attribute is set before
        // this callback fires. Fail loud (500 → Nightwatch) on a missing
        // attribute instead of falling back to $request->ip(): behind Cloudflare
        // every backend request shares a small pool of edge IPs, so an IP
        // fallback would silently bucket every tenant (and every auth failure)
        // into one shared limit. Matches the per-uid pattern used by every
        // other authenticated limiter in this file.
        RateLimiter::for('embedded-by-shop', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $shop = $request->attributes->get('embedded_shop_domain')
                ?? throw new \RuntimeException('embedded_shop_domain missing on embedded-by-shop route — VerifyShopifySessionToken middleware not applied or priority pin removed');

            return Limit::perMinute(60)
                ->by('embedded-shop:'.$shop)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many embedded app requests. Please wait a moment and try again.',
                    ], 429);
                });
        });

        // Public plans listing
        RateLimiter::for('plans', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(30)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429);
                });
        });
    }
}
