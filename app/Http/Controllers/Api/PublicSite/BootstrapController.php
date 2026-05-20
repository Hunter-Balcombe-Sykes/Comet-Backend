<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\AccountType;
use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Waitlist\WaitlistSignup;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\Brand\BrandSignupCodeService;
use App\Services\Professional\SiteProvisioningService;
use App\Services\Shopify\BrandSignupService;
use App\Services\Shopify\ShopifySetupTokenService;
use App\Services\Shopify\ShopProfileAutoFillService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

// V2: Account signup/update. Creates professional + site, applies type defaults, handles affiliate invite claims and brand partner connections. Entry point for affiliate/professional signup.
class BootstrapController extends ApiController
{
    public function __construct(
        private readonly SiteProvisioningService $siteProvisioning,
    ) {}

    public function bootstrap(
        BootstrapRequest $request,
        BrandAffiliateInviteService $brandAffiliateInviteService,
        BrandPartnerLinkService $brandPartnerLinks,
        AccountTypeDefaultsService $accountTypeDefaultsService
    ) {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        if ($this->isWaitlistModeEnabled() && ! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'New account creation is currently waitlist-only. Please join the waitlist.',
                403,
                ['code' => 'WAITLIST_ONLY']
            );
        }

        // §28.14 — Individual waitlist diversion (CFG-1). Runs BEFORE validation
        // because the invite-only validation rules would otherwise reject individual
        // signups before they reach this point. Brand, partner-via-invite, and
        // partner-via-brand-signup-code signups are unaffected.
        if (
            (bool) config('partna.individual_waitlist_enabled', false)
            && ! $this->hasExistingProfessional($uid)
            && ! (is_string($request->input('invite_token')) && trim((string) $request->input('invite_token')) !== '')
            && ! (is_string($request->input('brand_signup_code')) && trim((string) $request->input('brand_signup_code')) !== '')
            && strtolower(trim((string) $request->input('professional_type', ''))) !== 'brand'
        ) {
            $emailLc = strtolower(trim((string) $request->input('primary_email', '')));
            if ($emailLc !== '') {
                // updateOrCreate (not upsert) — production has a UNIQUE index on
                // email_lc, but using updateOrCreate keeps the divert
                // working consistently across environments and avoids relying on
                // the DB driver's ON CONFLICT clause shape.
                WaitlistSignup::query()->updateOrCreate(
                    ['email_lc' => $emailLc],
                    [
                        'email' => $emailLc,
                        'name' => trim(((string) $request->input('first_name', '')).' '.((string) $request->input('last_name', ''))) ?: null,
                        'applicant_type' => 'individual',
                        'consent_source' => 'individual_waitlist_divert',
                        'last_submitted_at' => now(),
                    ]
                );
            }

            return $this->error(
                'New individual signups are temporarily on a waitlist. We\'ll be in touch.',
                403,
                ['code' => 'INDIVIDUAL_WAITLIST']
            );
        }

        $data = $request->validated();

        // Brand signup code — rate-limit and resolve BEFORE the transaction so we can
        // return 429/422 responses without wrapping them in a DB transaction closure.
        // $resolvedSignupCodeBrand is non-null only when a valid code was supplied and passed.
        $resolvedSignupCodeBrand = null;
        $signupCodeAttempted = is_string($data['brand_signup_code'] ?? null) && trim((string) $data['brand_signup_code']) !== '';
        if ($signupCodeAttempted) {
            $attemptedCode = trim((string) $data['brand_signup_code']);
            $sourceIp = $request->header('CF-Connecting-IP') ?? $request->ip();
            $rateLimitKey = 'brand-signup-code:'.$sourceIp;

            // Progressive delay when same IP has recently failed — CFG-3.
            $failureCountKey = 'brand-signup-code-failures:'.$sourceIp;
            $recentFailures = (int) Cache::get($failureCountKey, 0);
            $delayThreshold = (int) config('partna.brand_signup_code.rate_limit.delay_after_failures', 5);
            $delaySeconds = (int) config('partna.brand_signup_code.rate_limit.delay_seconds', 2);
            if ($recentFailures >= $delayThreshold) {
                sleep($delaySeconds);
            }

            // Hard rate limits per IP.
            $perMinute = (int) config('partna.brand_signup_code.rate_limit.per_minute', 10);
            $perHour = (int) config('partna.brand_signup_code.rate_limit.per_hour', 100);
            if (RateLimiter::tooManyAttempts($rateLimitKey.':minute', $perMinute) ||
                RateLimiter::tooManyAttempts($rateLimitKey.':hour', $perHour)) {
                return $this->error('Too many signup code attempts. Please try again later.', 429);
            }
            RateLimiter::hit($rateLimitKey.':minute', 60);
            RateLimiter::hit($rateLimitKey.':hour', 3600);

            $resolvedSignupCodeBrand = app(BrandSignupCodeService::class)->resolveCode($attemptedCode);
            if (! $resolvedSignupCodeBrand) {
                Cache::put($failureCountKey, $recentFailures + 1, now()->addHour());
                app(BrandSignupCodeService::class)->recordFailedClaim($attemptedCode, $sourceIp);

                return $this->error('Code not recognized.', 422, ['code' => 'SIGNUP_CODE_NOT_FOUND']);
            }
        }

        try {
            $allowedProfessionalTypes = array_keys(config('partna.professional_types', []));
            $resolveProfessionalType = static function (mixed $candidate) use ($allowedProfessionalTypes): string {
                if (is_string($candidate)) {
                    $normalized = mb_strtolower(trim($candidate));
                    if ($normalized !== '' && in_array($normalized, $allowedProfessionalTypes, true)) {
                        return $normalized;
                    }
                }

                return 'professional';
            };

            // Captures the user-facing message when single-brand cap blocks the
            // signup_code attach. Must be a variable closed by-reference because
            // throwing from inside DB::transaction() would roll back the freshly
            // created Professional row — the user expects to keep their account
            // and just see an error about the brand-attach failing.
            $brandSignupCodeError = null;

            $result = DB::transaction(function () use ($uid, $data, $brandAffiliateInviteService, $brandPartnerLinks, $accountTypeDefaultsService, $resolveProfessionalType, $request, $resolvedSignupCodeBrand, &$brandSignupCodeError) {
                $createdProfessional = false;

                $professional = Professional::query()->where('auth_user_id', $uid)->first();

                if (! $professional) {
                    // Defensive: a different Supabase user already owns this email
                    // (e.g. signed up with password, now retrying via Google OAuth
                    // without identity-linking enabled). Surface a clean 409 instead
                    // of letting the unique-index on primary_email throw a 500.
                    $emailLc = strtolower(trim((string) $data['primary_email']));
                    if ($emailLc !== '') {
                        $existingByEmail = Professional::query()
                            ->whereRaw('lower(primary_email) = ?', [$emailLc])
                            ->where('auth_user_id', '!=', $uid)
                            ->exists();

                        if ($existingByEmail) {
                            throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
                        }
                    }

                    $createdProfessional = true;
                    $resolvedType = $resolveProfessionalType($data['professional_type'] ?? null);
                    // Plan §28.13 + non-negotiable #5/#12: BootstrapController is the
                    // ONLY writer of account_type='brand'. Default non-brand signups
                    // to 'individual'; the brand-attach branches below flip the
                    // freshly-created Professional to 'partner' on successful link.
                    $initialAccountType = $resolvedType === 'brand'
                        ? AccountType::Brand
                        : AccountType::Individual;
                    $professional = new Professional([
                        'handle' => $data['handle'],
                        'display_name' => $data['display_name'],
                        'bio' => null,
                        'country_code' => $data['country_code'] ?? null,
                        'timezone' => $data['timezone'] ?? null,
                        'professional_type' => $resolvedType,
                        'account_type' => $initialAccountType,
                        'status' => 'active',
                        'onboarding_step' => 0,
                        'phone' => $data['phone'] ?? null,
                        'primary_email' => $data['primary_email'],
                        'first_name' => $data['first_name'] ?? '',
                        'last_name' => $data['last_name'] ?? null,

                        'public_contact_number' => null,
                        'public_contact_email' => null,
                        'handle_lc' => $data['handle_lc'],
                    ]);
                    $professional->auth_user_id = $uid;
                } else {

                    if (in_array($professional->status, ['disabled', 'suspended', 'pending_deletion'], true)) {
                        return $this->error('Account is disabled. Contact support.', 403);
                    }

                    $fill = [
                        'handle' => $data['handle'],
                        'display_name' => $data['display_name'],
                        'primary_email' => $data['primary_email'],
                        'phone' => $data['phone'] ?? $professional->phone,
                        'first_name' => $data['first_name'] ?? $professional->first_name,
                        'last_name' => $data['last_name'] ?? $professional->last_name,
                        'country_code' => $data['country_code'] ?? $professional->country_code,
                        'timezone' => $data['timezone'] ?? $professional->timezone,
                        'professional_type' => $resolveProfessionalType($data['professional_type'] ?? $professional->professional_type),
                        'handle_lc' => $data['handle_lc'],
                    ];

                    if (array_key_exists('phone', $data)) {
                        $fill['phone'] = $data['phone'];
                    }

                    $professional->fill($fill);
                }
                $professional->save();

                // Add to Partna updates list once (global list). Do NOT overwrite if they already unsubscribed.
                $this->ensureSidestUpdatesSubscription($professional->primary_email);

                $site = Site::query()->where('professional_id', $professional->id)->first();

                if (! $site) {
                    $base = $this->siteProvisioning->subdomainBaseFromHandle($data['handle']);

                    $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
                }

                // Apply account-type defaults for new professionals
                if ($createdProfessional) {
                    $accountTypeDefaultsService->applyDefaults($professional, $site);

                    if ($professional->isBrand()) {
                        BrandProfile::firstOrCreate(
                            ['professional_id' => (string) $professional->id],
                            ['setup_complete' => false]
                        );
                    }
                }

                // Brand-attach branches: a connect attempt that hits the
                // single-brand cap (RuntimeException from the link service)
                // must NOT roll back the entire bootstrap. The affiliate's
                // existing brand connection is preserved; the new attempt is
                // skipped and surfaced in logs so the frontend can ask the
                // user to disconnect first if they want to switch.
                // Tracks whether any of the three brand-attach branches below
                // succeeded in creating a BrandPartnerLink. Used after the
                // branches to promote the new professional to 'partner'.
                $attachedAsPartner = false;

                if (is_string($data['invite_token'] ?? null) && trim((string) $data['invite_token']) !== '') {
                    $invite = $brandAffiliateInviteService->findByToken((string) $data['invite_token']);
                    if (! $invite) {
                        throw new RuntimeException('Invite not found.');
                    }

                    try {
                        $brandAffiliateInviteService->claimInvite($invite, $professional);
                        $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
                        $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site);
                        $attachedAsPartner = true;
                    } catch (RuntimeException $e) {
                        Log::warning('Bootstrap brand-attach via invite_token skipped', [
                            'professional_id' => (string) $professional->id,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                } elseif (is_string($data['brand_partner_professional_id'] ?? null) && trim((string) $data['brand_partner_professional_id']) !== '') {
                    $brandPartnerProfessional = Professional::query()
                        ->whereKey((string) $data['brand_partner_professional_id'])
                        ->where('professional_type', 'brand')
                        ->first();

                    if (! $brandPartnerProfessional) {
                        throw new RuntimeException('Brand partner not found.');
                    }

                    $affiliateId = (string) $professional->id;
                    $brandId = (string) $brandPartnerProfessional->id;

                    try {
                        if (! $brandPartnerLinks->isConnected($affiliateId, $brandId)) {
                            $brandPartnerLinks->connectBrandToAffiliate($affiliateId, $brandId);
                        }

                        $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, $affiliateId);
                        $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site);
                        $attachedAsPartner = true;
                    } catch (RuntimeException $e) {
                        Log::warning('Bootstrap brand-attach via brand_partner_professional_id skipped', [
                            'professional_id' => $affiliateId,
                            'brand_professional_id' => $brandId,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                } elseif (is_string($data['join_brand_handle'] ?? null) && trim((string) $data['join_brand_handle']) !== '') {
                    $joinBrand = Professional::query()
                        ->where('handle_lc', strtolower(trim((string) $data['join_brand_handle'])))
                        ->where('professional_type', 'brand')
                        ->with('brandProfile')
                        ->first();

                    if ($joinBrand) {
                        $joinBrandStatus = $joinBrand->brandProfile?->brand_status ?? BrandStatus::SystemsDown->value;

                        if ($joinBrandStatus !== BrandStatus::SystemsDown->value) {
                            $affiliateId = (string) $professional->id;
                            $brandId = (string) $joinBrand->id;

                            if (! $brandPartnerLinks->isConnected($affiliateId, $brandId)) {
                                try {
                                    $brandAffiliateInviteService->claimOpenInvite($joinBrand, $professional);
                                    $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, $affiliateId);
                                    $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site);
                                    $attachedAsPartner = true;
                                } catch (RuntimeException $e) {
                                    Log::warning('Bootstrap brand-attach via join_brand_handle skipped', [
                                        'professional_id' => $affiliateId,
                                        'brand_professional_id' => $brandId,
                                        'reason' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                    }
                }

                // Brand signup code — resolved and rate-limited BEFORE the transaction.
                // $resolvedSignupCodeBrand is set when a valid active code was supplied.
                if (! $attachedAsPartner && $resolvedSignupCodeBrand !== null) {
                    $brandProfessionalId = (string) $resolvedSignupCodeBrand->professional_id;
                    $affiliateId = (string) $professional->id;
                    $codeSourceIp = $request->header('CF-Connecting-IP') ?? $request->ip();

                    try {
                        if (! $brandPartnerLinks->isConnected($affiliateId, $brandProfessionalId)) {
                            $brandPartnerLinks->connectBrandToAffiliate($affiliateId, $brandProfessionalId);
                        }

                        app(BrandSignupCodeService::class)->recordClaim($resolvedSignupCodeBrand, $professional, $codeSourceIp);
                        $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, $affiliateId);
                        $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site);
                        $attachedAsPartner = true;
                    } catch (RuntimeException $e) {
                        Log::warning('Bootstrap brand-attach via brand_signup_code skipped', [
                            'professional_id' => $affiliateId,
                            'brand_professional_id' => $brandProfessionalId,
                            'reason' => $e->getMessage(),
                        ]);
                        // Surface to the user via the by-reference variable, NOT
                        // by rethrowing — rethrowing rolls back the freshly created
                        // Professional row and the user loses the account they just
                        // created. Service message is already user-facing.
                        $brandSignupCodeError = $e->getMessage();
                    }
                }

                // Shopify setup token: create integration from cached OAuth credentials
                $shopifyIntegrationId = null;
                $shopifySetupToken = is_string($data['shopify_setup_token'] ?? null) ? trim((string) $data['shopify_setup_token']) : '';
                if ($shopifySetupToken !== '') {
                    // Peek first — consume only after transaction succeeds (prevents token loss on rollback)
                    $shopifyData = app(ShopifySetupTokenService::class)->peek($shopifySetupToken);
                    if ($shopifyData === null) {
                        throw new RuntimeException('Shopify setup session is invalid or expired. Please reinstall the app from Shopify.');
                    }

                    $shopDomain = $shopifyData['shop_domain'];
                    $shopId = trim((string) Arr::get($shopifyData['shop_data'], 'id', ''));
                    $shopCurrency = strtoupper(trim((string) Arr::get($shopifyData['shop_data'], 'currency', '')));

                    $integration = ProfessionalIntegration::create([
                        'professional_id' => (string) $professional->id,
                        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
                        'external_account_id' => $shopDomain,
                        'access_token' => $shopifyData['access_token'],
                        'provider_metadata' => [
                            'shop_domain' => $shopDomain,
                            'shop_id' => $shopId !== '' ? "gid://shopify/Shop/{$shopId}" : null,
                            'shop_currency' => $shopCurrency !== '' ? $shopCurrency : null,
                            'scopes' => $shopifyData['scopes'],
                            'webhook_orders_topic' => config('services.shopify.webhook_orders_topic', 'orders/paid'),
                            'connected_at' => now()->toIso8601String(),
                        ],
                        // Post-DATA-2: column-backed gate (RegisterShopifyWebhooksJob
                        // flips this to 'registered'/'partial'/'failed' on first run).
                        'webhook_registration_state' => 'queued',
                    ]);

                    $shopifyIntegrationId = (string) $integration->id;

                    // Auto-fill profile from Shopify shop data (address, phone, etc. — not email)
                    $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();
                    app(ShopProfileAutoFillService::class)->fillFromShopData(
                        $professional, $site, $brandProfile, $shopifyData['shop_data']
                    );
                }

                // Plan §28.13: promote freshly-created non-brand professionals
                // to 'partner' once a BrandPartnerLink has actually been
                // established by one of the three brand-attach branches above.
                // Brand accounts never transition; partner-via-link is the only
                // post-creation account_type write in this controller.
                if ($attachedAsPartner && ! $professional->isBrand() && ! $professional->isPartner()) {
                    $professional->account_type = AccountType::Partner;
                    $professional->save();
                }

                app(ProfessionalCacheService::class)->invalidateProfessional($professional);

                // Ensure the professional has a subscription – seed the free plan if none exists
                $this->siteProvisioning->ensureFreeSubscription($professional);

                if ($createdProfessional) {
                    $this->createWelcomeNotification($professional);
                }

                return [
                    'professional' => new ProfessionalDashboardResource($professional->fresh()),
                    'site' => $site->fresh(),
                    'shopify_integration_id' => $shopifyIntegrationId,
                ];
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'EMAIL_ALREADY_REGISTERED') {
                Log::info('Bootstrap rejected: email already registered to another auth user', [
                    'uid' => $uid,
                    'email' => $data['primary_email'] ?? null,
                ]);

                return $this->error(
                    'This email is already associated with a different account. Sign in with your original method, or contact support to link accounts.',
                    409,
                    ['code' => 'EMAIL_ALREADY_REGISTERED']
                );
            }

            Log::error('Bootstrap transaction failed', [
                'error' => $e->getMessage(),
                'uid' => $uid,
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Bootstrap transaction failed', [
                'error' => $e->getMessage(),
                'uid' => $uid,
            ]);
            throw $e;
        }

        // Consume Shopify setup token AFTER transaction succeeds (prevents token loss on rollback)
        if (is_string($result['shopify_integration_id'] ?? null)) {
            $shopifySetupToken = trim((string) ($data['shopify_setup_token'] ?? ''));
            if ($shopifySetupToken !== '') {
                app(ShopifySetupTokenService::class)->consume($shopifySetupToken);
            }
            app(BrandSignupService::class)->dispatchInstallJobs($result['shopify_integration_id']);
        }

        // Strip internal ID before returning
        unset($result['shopify_integration_id']);

        // Surface signup-code single-brand-cap failure after a successful txn —
        // the Professional/Site rows are preserved; only the brand-attach failed.
        // Wrap the success payload so the frontend can still navigate to the
        // dashboard but knows to show the error toast.
        if ($brandSignupCodeError !== null) {
            return $this->error($brandSignupCodeError, 422, [
                'code' => 'BRAND_SIGNUP_CODE_CAP_EXCEEDED',
                'partial_success' => $result,
            ]);
        }

        return $this->success($result);
    }

    private function ensureSidestUpdatesSubscription(?string $email): void
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if ($email === '') {
            return;
        }

        $listKey = 'sidest_updates';

        $existing = EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $listKey)
            ->where('email_lc', $email)
            ->first();

        if ($existing) {
            return; // keep whatever status they chose
        }

        $sub = new EmailSubscription([
            'professional_id' => null,
            'list_key' => $listKey,
            'email' => $email,
            'email_lc' => $email,
            'full_name' => null,
            'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
        ]);

        $sub->markSubscribed(['source' => 'bootstrap']);
        $sub->save();
    }

    private function createWelcomeNotification(Professional $professional): void
    {
        Notification::query()->firstOrCreate(
            [
                'professional_id' => $professional->id,
                'type' => 'Info',
                'title' => 'Welcome to Partna',
            ],
            [
                'body' => 'Your account is ready. Complete your profile, connect with brands, and start tracking your commissions — all from your dashboard.',
                'cta_url' => null,
                'severity' => 'info',
                'starts_at' => now(),
                'ends_at' => null,
            ]
        );
    }

    private function syncSiteBrandPartnerSettings(
        Site $site,
        BrandPartnerLinkService $brandPartnerLinks,
        string $affiliateProfessionalId
    ): void {
        $links = $brandPartnerLinks->getLinksForAffiliate($affiliateProfessionalId);
        $settings = is_array($site->settings) ? $site->settings : [];

        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : [];

        $primaryLink = $links->firstWhere('slot', BrandPartnerLinkService::PRIMARY_SLOT);
        if ($primaryLink) {
            $brandPartner['professional_id'] = (string) $primaryLink->brand_professional_id;
        } else {
            unset($brandPartner['professional_id'], $brandPartner['professionalId']);
        }

        $settings['brand_partner'] = $brandPartner;
        $settings['additional_brand_partners'] = $links
            ->filter(static fn ($link): bool => (int) $link->slot > BrandPartnerLinkService::PRIMARY_SLOT)
            ->sortBy('slot')
            ->map(static fn ($link): array => [
                'professional_id' => (string) $link->brand_professional_id,
            ])
            ->values()
            ->all();

        $site->settings = $settings;
        $site->save();
    }

    private function isWaitlistModeEnabled(): bool
    {
        return (bool) config('partna.waitlist.enabled', false);
    }

    private function hasExistingProfessional(string $uid): bool
    {
        return Professional::query()
            ->where('auth_user_id', $uid)
            ->exists();
    }
}
