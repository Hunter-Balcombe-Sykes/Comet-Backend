<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Stripe v2 Accounts + destination-charge plumbing.
 *
 * Brands get a single v2 Account with three configurations:
 *   - merchant   → card_payments capability (so the destination charge has a settlement merchant)
 *   - customer   → enables PaymentMethod storage on the brand's own Account (the Account IS the customer)
 *   - recipient  → stripe_balance.stripe_transfers (required for on_behalf_of destination charges)
 *
 * Affiliates get a v2 Account with only the recipient configuration — they receive transfers, they
 * never charge a customer themselves.
 *
 * Responsibilities are pinned to the platform: fees_collector=application and losses_collector=application.
 * Stripe processing fees come out of our application_fee_amount, and Partna bears risk on negative balances.
 *
 * The status cache wraps a single v2 account retrieve per professional and is busted by the platform
 * webhook (v2.core.account.* events) and by `?fresh=1` after onboarding return.
 */
class StripeConnectService
{
    private StripeClient $stripe;

    private const STATUS_CACHE_TTL = 60;

    public function __construct(private readonly CacheLockService $cacheLock)
    {
        $this->stripe = new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
    }

    /**
     * Cache key for a connected account's status payload. Keyed on the Stripe
     * account ID so the webhook bust path can forget the cache without a DB
     * lookup — the v2.core.account.* event carries the account ID in
     * data.related_object.id.
     */
    public static function statusCacheKey(string $accountId): string
    {
        return 'stripe:connect:status:'.$accountId;
    }

    /**
     * Evict the cached account status. MUST clear the SWR ":stale" copy too —
     * forgetting only the primary key would leave the stale-while-revalidate
     * last-good copy live for up to 10×TTL, defeating the bust entirely.
     */
    public static function forgetStatusCache(string $accountId): void
    {
        $key = self::statusCacheKey($accountId);
        Cache::forget($key);
        Cache::forget($key.':stale');
    }

    /**
     * Dispatch: create a brand or affiliate v2 Account based on professional type.
     * Persists the new account ID + status='onboarding' to the professional.
     */
    public function createConnectAccount(Professional $professional): string
    {
        if (! is_string($professional->country_code) || trim($professional->country_code) === '') {
            abort(
                422,
                'Cannot create a Stripe Connect account without a country. Please set your country on your profile before connecting Stripe.'
            );
        }

        $accountId = $professional->isBrand()
            ? $this->createBrandConnectAccount($professional)
            : $this->createAffiliateConnectAccount($professional);

        $professional->update([
            'stripe_connect_account_id' => $accountId,
            'stripe_connect_status' => 'onboarding',
        ]);

        return $accountId;
    }

    /**
     * Create a brand v2 Account with merchant + customer + recipient configurations.
     *
     * - merchant.card_payments: brand is settlement merchant on the destination charge,
     *   so Stripe needs card_payments on the brand's Account.
     * - customer (empty config): enables PaymentMethod storage scoped to this Account.
     *   The brand's saved card lives here.
     * - recipient.stripe_balance.stripe_transfers: required for the destination charge's
     *   on_behalf_of=brand_acct → transfer_data.destination=affiliate_acct flow. Without
     *   this capability the PI create rejects with an invalid_request_error.
     *
     * Dashboard=express gives the brand a hosted dashboard to resolve KYC issues and
     * view their charge history. Responsibilities are application-collected so Stripe
     * processing fees come out of our application_fee_amount and Partna bears negative
     * balances (mitigated by the order grace period before a payout settles).
     */
    public function createBrandConnectAccount(Professional $brand): string
    {
        $payload = [
            'contact_email' => $this->stringOrNull($brand->primary_email),
            'identity' => $this->buildBrandIdentityPayload($brand),
            'configuration' => [
                'merchant' => [
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                    ],
                ],
                // Empty object — presence of the customer config is what enables PM storage.
                'customer' => (object) [],
                'recipient' => [
                    'capabilities' => [
                        'stripe_balance' => [
                            'stripe_transfers' => ['requested' => true],
                        ],
                    ],
                ],
            ],
            'defaults' => [
                'responsibilities' => [
                    'fees_collector' => 'application',
                    'losses_collector' => 'application',
                ],
                'currency' => strtolower($this->resolveShopCurrency($brand) ?? 'aud'),
            ],
            'dashboard' => 'express',
            'metadata' => [
                'sidest_professional_id' => $brand->id,
                'professional_type' => $brand->professional_type, // legacy — kept for Nightwatch filters
                'account_type' => $brand->account_type,
            ],
        ];

        $account = $this->stripe->v2->core->accounts->create(
            $this->filterNullsRecursive($payload),
            ['idempotency_key' => "acct_brand_{$brand->id}"],
        );

        return $account->id;
    }

    /**
     * Create an affiliate v2 Account with recipient configuration only.
     *
     * Affiliates receive transfers from the platform balance (auto-routed by destination
     * charges) and pay out to their external bank. They never charge a customer themselves,
     * so no merchant or customer configuration is requested.
     */
    public function createAffiliateConnectAccount(Professional $affiliate): string
    {
        $payload = [
            'contact_email' => $this->stringOrNull($affiliate->primary_email),
            'identity' => $this->buildAffiliateIdentityPayload($affiliate),
            'configuration' => [
                'recipient' => [
                    'capabilities' => [
                        'stripe_balance' => [
                            'stripe_transfers' => ['requested' => true],
                        ],
                    ],
                ],
            ],
            'defaults' => [
                'responsibilities' => [
                    'fees_collector' => 'application',
                    'losses_collector' => 'application',
                ],
                'currency' => strtolower($this->resolveShopCurrency($affiliate) ?? 'aud'),
            ],
            'dashboard' => 'express',
            'metadata' => [
                'sidest_professional_id' => $affiliate->id,
                'professional_type' => $affiliate->professional_type, // legacy — kept for Nightwatch filters
                'account_type' => $affiliate->account_type,
            ],
        ];

        $account = $this->stripe->v2->core->accounts->create(
            $this->filterNullsRecursive($payload),
            ['idempotency_key' => "acct_affiliate_{$affiliate->id}"],
        );

        return $account->id;
    }

    /**
     * Create a v2 Account onboarding link.
     *
     * For brands we list all three configurations so the hosted flow collects merchant
     * KYC, customer PM consent (covered by the separate Checkout setup session, but
     * declaring the config here gates capability activation), and recipient details.
     * Affiliates only need recipient.
     *
     * ?fresh=1 is appended to return_url so the dashboard's first /stripe/status call
     * after redirect skips the cache and shows live capability state instead of racing
     * the v2.core.account.* webhook delivery.
     */
    public function createOnboardingLink(Professional $professional, string $returnUrl, string $refreshUrl): string
    {
        $accountId = $professional->stripe_connect_account_id;

        if (! $accountId) {
            $accountId = $this->createConnectAccount($professional);
        } elseif ($professional->stripe_connect_status === 'not_connected') {
            $professional->update(['stripe_connect_status' => 'onboarding']);
        }

        $configurations = $professional->isBrand()
            ? ['merchant', 'customer', 'recipient']
            : ['recipient'];

        $returnUrlWithBypass = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'fresh=1';

        $link = $this->stripe->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'configurations' => $configurations,
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrlWithBypass,
                ],
            ],
        ]);

        return $link->url;
    }

    /**
     * Create a v2 Express dashboard link.
     *
     * Available to active and restricted accounts so restricted-state users can resolve
     * KYC issues themselves. not_connected/onboarding accounts return null — the
     * frontend routes them to createOnboardingLink instead.
     */
    public function createDashboardLink(Professional $professional): ?string
    {
        $accountId = $professional->stripe_connect_account_id;

        if (! $accountId || ! in_array($professional->stripe_connect_status, ['active', 'restricted'], true)) {
            return null;
        }

        try {
            // v1 login_links is the correct API for Express dashboard access — it works on v2
            // accounts and returns a one-time URL. There is no v2 equivalent today; the v2
            // accountLinks API only supports account_onboarding and account_update use cases.
            $link = $this->stripe->accounts->createLoginLink($accountId);

            return $link->url;
        } catch (ApiErrorException $e) {
            report($e);
            Log::error('Stripe Express dashboard login link failed', [
                'professional_id' => $professional->id,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Read the v2 Account, derive status from capabilities, persist if changed.
     *
     * Cache wraps the Stripe round-trip only; the not_connected early-returns are
     * already free. Bust paths: ?fresh=1 on the controller (post-onboarding redirect)
     * and v2.core.account.* events on the platform-thin webhook.
     *
     * @return array{
     *     status: string,
     *     stripe_connect_account_id: ?string,
     *     card_payments_active: bool,
     *     stripe_transfers_active: bool,
     *     requirements: array<int, string>
     * }
     */
    public function syncAccountStatus(Professional $professional): array
    {
        $accountId = $professional->stripe_connect_account_id;

        if (! $accountId) {
            return $this->disconnectedStatusPayload();
        }

        if ($professional->stripe_connect_status === 'not_connected') {
            return $this->disconnectedStatusPayload();
        }

        return $this->cacheLock->rememberLocked(
            self::statusCacheKey($accountId),
            self::STATUS_CACHE_TTL,
            fn () => $this->fetchAndSyncAccountStatus($professional, $accountId),
        );
    }

    /**
     * Single Stripe round-trip + DB sync. Status is derived dual-capability against the
     * v2 account configuration (see determineAccountStatus for the rules).
     *
     * @return array{
     *     status: string,
     *     stripe_connect_account_id: string,
     *     card_payments_active: bool,
     *     stripe_transfers_active: bool,
     *     requirements: array<int, string>
     * }
     */
    private function fetchAndSyncAccountStatus(Professional $professional, string $accountId): array
    {
        $account = $this->stripe->v2->core->accounts->retrieve($accountId, [
            'include' => ['configuration.merchant', 'configuration.customer', 'configuration.recipient', 'requirements'],
        ]);

        $status = self::determineAccountStatus($account, $professional);

        if ($professional->stripe_connect_status !== $status) {
            $professional->update(['stripe_connect_status' => $status]);
        }

        return [
            'status' => $status,
            'stripe_connect_account_id' => $accountId,
            'card_payments_active' => self::capabilityIsActive($account, 'configuration.merchant.capabilities.card_payments.status'),
            'stripe_transfers_active' => self::capabilityIsActive($account, 'configuration.recipient.capabilities.stripe_balance.stripe_transfers.status'),
            'requirements' => $this->extractRequirements($account),
        ];
    }

    /**
     * Derive the canonical local status from a v2 Account object.
     *
     * Dual-capability check (audit P0-4):
     *   - Brand: BOTH card_payments AND stripe_transfers must be 'active', AND no
     *     requirements.currently_due. Either capability alone or both with outstanding
     *     requirements → 'restricted'. Neither → 'onboarding'.
     *   - Affiliate: only stripe_transfers.status === 'active' matters.
     *
     * Without the dual check, a brand whose card_payments activated but whose
     * stripe_transfers is still 'pending' would surface as 'active' locally while
     * every destination-charge PI silently failed at Stripe.
     */
    public static function determineAccountStatus(object $account, Professional $professional): string
    {
        $transfersActive = self::capabilityIsActive($account, 'configuration.recipient.capabilities.stripe_balance.stripe_transfers.status');

        if (! $professional->isBrand()) {
            return $transfersActive ? 'active' : 'onboarding';
        }

        $cardActive = self::capabilityIsActive($account, 'configuration.merchant.capabilities.card_payments.status');
        $requirementsDue = ! empty(data_get($account, 'requirements.currently_due', []));

        if ($cardActive && $transfersActive && ! $requirementsDue) {
            return 'active';
        }

        if ($cardActive || $transfersActive || $requirementsDue) {
            return 'restricted';
        }

        return 'onboarding';
    }

    /**
     * Soft-disconnect: null the Account ID and PM fields locally. Stripe-side the v2
     * Account still exists with its KYC intact, but we lose the link entirely — the
     * next onboarding creates a fresh Account.
     *
     * (C6 in the audit: previous plan kept the account ID to support "reconnect with
     * one click", but v2 Accounts can't be cleanly re-attached the same way, so we
     * drop the reference and the user re-onboards from scratch if they come back.)
     */
    public function disconnectAccount(Professional $professional): void
    {
        $accountId = $professional->stripe_connect_account_id;

        $professional->update([
            'stripe_connect_account_id' => null,
            'stripe_connect_status' => 'not_connected',
            'stripe_payment_method_id' => null,
            'stripe_payment_method_brand' => null,
            'stripe_payment_method_last4' => null,
        ]);

        if ($accountId !== null) {
            self::forgetStatusCache($accountId);
        }
    }

    /**
     * Create a Checkout session that saves a card to the brand's v2 Account.
     *
     * customer_account ties the SetupIntent to the brand's v2 Account directly (no
     * separate v1 Customer object). The session runs on the platform — no
     * stripe_account header — because v2 Accounts are addressed by ID, not header.
     *
     * @return array{checkout_url: string, session_id: string}
     */
    public function createBrandPaymentMethodSetupSession(
        Professional $brand,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $this->assertBrandIsActive($brand);

        return $this->createCheckoutSetupSession($brand, $successUrl, $cancelUrl, ['card']);
    }

    /**
     * Create a Checkout session that saves a BECS Direct Debit mandate to the brand's
     * v2 Account. Stripe Checkout collects the BSB + account number and renders the
     * mandate acceptance UI automatically.
     *
     * BECS settles T+2 and carries a 7-year dispute window under NPPA rules. With
     * losses_collector='application' Partna bears that exposure — disputed BECS payments
     * within the 7-year window are debited from the platform balance regardless of when
     * they occurred. Any operator-level limit on which brands can use BECS lives outside
     * this service (none exists today).
     *
     * @return array{checkout_url: string, session_id: string}
     */
    public function createBrandBecsSetupSession(
        Professional $brand,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $this->assertBrandIsActive($brand);

        return $this->createCheckoutSetupSession($brand, $successUrl, $cancelUrl, ['au_becs_debit']);
    }

    /**
     * @param  array<int, string>  $paymentMethodTypes
     * @return array{checkout_url: string, session_id: string}
     */
    private function createCheckoutSetupSession(
        Professional $brand,
        string $successUrl,
        string $cancelUrl,
        array $paymentMethodTypes,
    ): array {
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'setup',
            'customer_account' => $brand->stripe_connect_account_id,
            'payment_method_types' => $paymentMethodTypes,
            'success_url' => $this->appendCheckoutSessionParam($successUrl, 'stripe_pm_session_id'),
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'purpose' => 'brand_commission_payment_method',
                'sidest_professional_id' => $brand->id,
                'requested_method' => $paymentMethodTypes[0] ?? 'card',
            ],
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Persist a saved payment method from a completed Checkout setup session.
     *
     * Handles both card and BECS paths — payout_method is derived from the PM type
     * Stripe returns ('card' → 'card', 'au_becs_debit' → 'becs'). The masked-display
     * columns (brand, last4) cover both — for BECS, last4 is the account number tail
     * and brand stores 'bank' or the financial institution name when Stripe provides it.
     *
     * @return array{payment_method_id: string, payout_method: string}
     */
    public function syncBrandPaymentMethodFromCheckoutSession(Professional $brand, string $sessionId): array
    {
        if (! $brand->stripe_connect_account_id) {
            throw new \RuntimeException('Brand has no Stripe Connect account.');
        }

        $session = $this->stripe->checkout->sessions->retrieve(
            $sessionId,
            ['expand' => ['setup_intent.payment_method']],
        );

        if (($session->mode ?? null) !== 'setup') {
            throw new \RuntimeException('Checkout session is not a setup session.');
        }

        if (($session->status ?? null) !== 'complete') {
            throw new \RuntimeException('Setup session is not complete yet.');
        }

        $metadataProId = $session->metadata?->sidest_professional_id ?? null;
        if ($metadataProId !== null && $metadataProId !== $brand->id) {
            throw new \RuntimeException('Setup session does not belong to this account.');
        }

        $setupIntent = $session->setup_intent;
        if (is_string($setupIntent) || $setupIntent === null) {
            throw new \RuntimeException('Setup session missing expanded setup intent.');
        }

        if (($setupIntent->status ?? null) !== 'succeeded') {
            throw new \RuntimeException('Setup intent has not succeeded.');
        }

        $paymentMethod = $setupIntent->payment_method;
        if (is_string($paymentMethod) || $paymentMethod === null) {
            throw new \RuntimeException('No payment method found on setup intent.');
        }

        [$payoutMethod, $brandLabel, $last4] = $this->extractPaymentMethodDisplay($paymentMethod);

        // Phase 4 — multi-PM. Dual-write: type-specific columns are the new source of truth,
        // legacy columns continue to mirror the most-recently-saved PM as a snapshot of the
        // "primary" so old callers keep working. preferred_payout_method seeded from the
        // first PM set up; user can override later via PUT /stripe/payment-method/preference.
        $update = [
            'stripe_payment_method_id' => $paymentMethod->id,
            'stripe_payment_method_brand' => $brandLabel,
            'stripe_payment_method_last4' => $last4,
            'payout_method' => $payoutMethod,
        ];

        if ($payoutMethod === 'becs') {
            $update['stripe_becs_payment_method_id'] = $paymentMethod->id;
            $update['stripe_becs_bsb'] = $brandLabel;
            $update['stripe_becs_last4'] = $last4;
        } else {
            $update['stripe_card_payment_method_id'] = $paymentMethod->id;
            $update['stripe_card_brand'] = $brandLabel;
            $update['stripe_card_last4'] = $last4;
        }

        // First PM sets the preference; subsequent sync calls don't override the user's
        // explicit choice (changed via PUT /stripe/payment-method/preference).
        if ($brand->preferred_payout_method === null) {
            $update['preferred_payout_method'] = $payoutMethod;
        }

        $brand->update($update);

        return [
            'payment_method_id' => $paymentMethod->id,
            'payout_method' => $payoutMethod,
        ];
    }

    /**
     * Phase 4 — set the brand's preferred payout method explicitly. Validates that the chosen
     * type has a PM ID present; throws if not (the controller wraps that in a 422).
     */
    public function setBrandPreferredPayoutMethod(Professional $brand, string $method): void
    {
        if (! in_array($method, ['card', 'becs'], true)) {
            throw new \InvalidArgumentException('Invalid payout method.');
        }

        $hasCard = ! empty($brand->stripe_card_payment_method_id);
        $hasBecs = ! empty($brand->stripe_becs_payment_method_id);

        if ($method === 'card' && ! $hasCard) {
            throw new \RuntimeException('No card payment method on file.');
        }
        if ($method === 'becs' && ! $hasBecs) {
            throw new \RuntimeException('No BECS direct debit on file.');
        }

        // Keep legacy columns in sync with the new preferred — old callers expect them.
        $brand->update([
            'preferred_payout_method' => $method,
            'payout_method' => $method,
            'stripe_payment_method_id' => $method === 'card'
                ? $brand->stripe_card_payment_method_id
                : $brand->stripe_becs_payment_method_id,
            'stripe_payment_method_brand' => $method === 'card'
                ? $brand->stripe_card_brand
                : $brand->stripe_becs_bsb,
            'stripe_payment_method_last4' => $method === 'card'
                ? $brand->stripe_card_last4
                : $brand->stripe_becs_last4,
        ]);
    }

    /**
     * Detach the brand's saved PaymentMethod from the v2 Account and null the local
     * cache columns. Tolerant of "already detached" / 404 — the user state is what
     * matters; the PM is gone from Stripe one way or another.
     */
    public function removeBrandPaymentMethod(Professional $brand, ?string $type = null): void
    {
        // Phase 4 — multi-PM removal. When $type is null we behave like the pre-multi-PM
        // version and remove the primary (legacy stripe_payment_method_id). When $type is
        // explicit, only that type's columns are cleared; the other PM stays in place.
        if ($type === null) {
            $type = $brand->preferred_payout_method ?? $brand->payout_method;
        }

        if ($type === 'becs') {
            $becsId = $brand->stripe_becs_payment_method_id;
            if ($becsId !== null) {
                try {
                    $this->stripe->paymentMethods->detach($becsId);
                } catch (ApiErrorException) {
                }
            }
            $newPrimary = $brand->stripe_card_payment_method_id;
            $brand->update([
                'stripe_becs_payment_method_id' => null,
                'stripe_becs_bsb' => null,
                'stripe_becs_last4' => null,
                'preferred_payout_method' => $newPrimary ? 'card' : null,
                'payout_method' => $newPrimary ? 'card' : null,
                'stripe_payment_method_id' => $newPrimary,
                'stripe_payment_method_brand' => $newPrimary ? $brand->stripe_card_brand : null,
                'stripe_payment_method_last4' => $newPrimary ? $brand->stripe_card_last4 : null,
            ]);

            return;
        }

        if ($type === 'card') {
            $cardId = $brand->stripe_card_payment_method_id;
            if ($cardId !== null) {
                try {
                    $this->stripe->paymentMethods->detach($cardId);
                } catch (ApiErrorException) {
                }
            }
            $newPrimary = $brand->stripe_becs_payment_method_id;
            $brand->update([
                'stripe_card_payment_method_id' => null,
                'stripe_card_brand' => null,
                'stripe_card_last4' => null,
                'preferred_payout_method' => $newPrimary ? 'becs' : null,
                'payout_method' => $newPrimary ? 'becs' : null,
                'stripe_payment_method_id' => $newPrimary,
                'stripe_payment_method_brand' => $newPrimary ? $brand->stripe_becs_bsb : null,
                'stripe_payment_method_last4' => $newPrimary ? $brand->stripe_becs_last4 : null,
            ]);

            return;
        }

        // Fallback — no type-specific path and no preference — clear legacy fields only.
        $paymentMethodId = $brand->stripe_payment_method_id;
        if ($paymentMethodId !== null) {
            try {
                $this->stripe->paymentMethods->detach($paymentMethodId);
            } catch (ApiErrorException) {
            }
        }
        $brand->update([
            'stripe_payment_method_id' => null,
            'stripe_payment_method_brand' => null,
            'stripe_payment_method_last4' => null,
            'payout_method' => null,
            'preferred_payout_method' => null,
        ]);
    }

    /**
     * True iff the brand has a saved payment method ready for commission destination charges.
     */
    public function brandHasPaymentMethod(Professional $brand): bool
    {
        return ! empty($brand->stripe_payment_method_id)
            || ! empty($brand->stripe_card_payment_method_id)
            || ! empty($brand->stripe_becs_payment_method_id);
    }

    /**
     * Phase 4 — resolve which PM to charge for a brand. Returns null when no PM is available.
     *
     * Selection order:
     *   1. preferred_payout_method when set AND that type's id is present
     *   2. BECS if becs_payment_method_id is present (default fallback — preferred ledger settlement)
     *   3. card if card_payment_method_id is present
     *   4. legacy stripe_payment_method_id + payout_method (rows not migrated yet)
     *
     * @return array{id: string, type: string}|null type is the Stripe payment_method_types value ('card' | 'au_becs_debit')
     */
    public function resolveBrandPaymentMethod(Professional $brand): ?array
    {
        $cardId = $brand->stripe_card_payment_method_id ?? null;
        $becsId = $brand->stripe_becs_payment_method_id ?? null;
        $preferred = $brand->preferred_payout_method ?? null;

        if ($preferred === 'card' && $cardId) {
            return ['id' => $cardId, 'type' => 'card'];
        }
        if ($preferred === 'becs' && $becsId) {
            return ['id' => $becsId, 'type' => 'au_becs_debit'];
        }

        if ($becsId) {
            return ['id' => $becsId, 'type' => 'au_becs_debit'];
        }
        if ($cardId) {
            return ['id' => $cardId, 'type' => 'card'];
        }

        // Legacy fallback — rows from before the multi-PM migration land in this branch
        // because the backfill writes type-specific columns alongside; this catches the
        // sliver of rows whose backfill didn't run (e.g. tests, fresh installs).
        if (! empty($brand->stripe_payment_method_id)) {
            $type = ($brand->payout_method ?? null) === 'becs' ? 'au_becs_debit' : 'card';

            return ['id' => $brand->stripe_payment_method_id, 'type' => $type];
        }

        return null;
    }

    /**
     * Resolve the shop currency from the professional's Shopify integration, if present.
     * Returns null when no Shopify integration exists or shop_currency is not yet set.
     */
    private function resolveShopCurrency(Professional $professional): ?string
    {
        $integration = $this->resolveShopifyIntegration($professional);
        if (! $integration) {
            return null;
        }

        $currency = Arr::get($integration->provider_metadata ?? [], 'shop_currency');

        return ($currency && is_string($currency)) ? strtoupper(trim($currency)) : null;
    }

    /**
     * Phase 5 — extract Shopify shop metadata used to prefill the v2 Account create call.
     *
     * @return array{shop_name: ?string, shop_url: ?string, shop_domain: ?string}
     */
    private function resolveShopMetadata(Professional $professional): array
    {
        $integration = $this->resolveShopifyIntegration($professional);
        $meta = $integration?->provider_metadata ?? [];

        $shopName = $this->stringOrNull(Arr::get($meta, 'shop_name') ?? Arr::get($meta, 'name'));
        $shopDomain = $this->stringOrNull(Arr::get($meta, 'shop_domain') ?? Arr::get($meta, 'myshopify_domain'));
        $shopUrl = $this->stringOrNull(Arr::get($meta, 'shop_url') ?? Arr::get($meta, 'primary_domain'));

        // If we have a shop_domain but no shop_url, derive the canonical URL.
        if ($shopUrl === null && $shopDomain !== null) {
            $shopUrl = "https://{$shopDomain}";
        }

        return [
            'shop_name' => $shopName,
            'shop_url' => $shopUrl,
            'shop_domain' => $shopDomain,
        ];
    }

    private function resolveShopifyIntegration(Professional $professional): ?ProfessionalIntegration
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
    }

    /**
     * @return array{
     *     status: string,
     *     stripe_connect_account_id: ?string,
     *     card_payments_active: bool,
     *     stripe_transfers_active: bool,
     *     requirements: array<int, string>
     * }
     */
    private function disconnectedStatusPayload(): array
    {
        return [
            'status' => 'not_connected',
            'stripe_connect_account_id' => null,
            'card_payments_active' => false,
            'stripe_transfers_active' => false,
            'requirements' => [],
        ];
    }

    private function assertBrandIsActive(Professional $brand): void
    {
        if (! $brand->stripe_connect_account_id) {
            throw new \RuntimeException('Brand must complete Stripe Connect onboarding before adding a payment method.');
        }

        if (! in_array($brand->stripe_connect_status, ['active', 'restricted'], true)) {
            throw new \RuntimeException('Brand Stripe Connect account is not active.');
        }
    }

    /**
     * Build the v2 identity block for a brand, with prefill from Shopify shop metadata.
     *
     * Stripe-hosted onboarding skips steps where prefilled values pass validation, so the
     * brand only fills in bank details + ID verification. Falls back to bare minimum
     * (entity_type + country) if Shopify isn't connected yet.
     *
     * @return array<string, mixed>
     */
    private function buildBrandIdentityPayload(Professional $brand): array
    {
        $shop = $this->resolveShopMetadata($brand);

        $business = array_filter([
            'name' => $shop['shop_name'] ?? $brand->display_name,
            'business_profile' => array_filter([
                'url' => $shop['shop_url'],
                'mcc' => '5734',
                'product_description' => 'Affiliate-driven product sales via Partna',
            ]),
        ]);

        return array_filter([
            'entity_type' => 'company',
            'country' => $this->mapCountryCode($brand->country_code),
            'business' => empty($business) ? null : $business,
        ]);
    }

    /**
     * Build the v2 identity block for an affiliate, with prefill from Partna signup data.
     *
     * Stripe-hosted onboarding skips identity fields it already has — affiliate only needs
     * to add bank details + ID verification.
     *
     * @return array<string, mixed>
     */
    private function buildAffiliateIdentityPayload(Professional $affiliate): array
    {
        $firstName = $this->stringOrNull($affiliate->first_name);
        $lastName = $this->stringOrNull($affiliate->last_name);

        // If first/last aren't split out, fall back to splitting display_name on the first space.
        if ($firstName === null && $lastName === null && $this->stringOrNull($affiliate->display_name)) {
            $parts = preg_split('/\s+/', trim((string) $affiliate->display_name), 2) ?: [];
            $firstName = $parts[0] ?? null;
            $lastName = $parts[1] ?? null;
        }

        $individual = array_filter([
            'given_name' => $firstName,
            'surname' => $lastName,
            'email' => $this->stringOrNull($affiliate->primary_email),
        ]);

        return array_filter([
            'entity_type' => 'individual',
            'country' => $this->mapCountryCode($affiliate->country_code),
            'individual' => empty($individual) ? null : $individual,
        ]);
    }

    /**
     * Extract masked-display fields from a PaymentMethod, returning [payout_method, brand_label, last4].
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function extractPaymentMethodDisplay(object $paymentMethod): array
    {
        $type = (string) ($paymentMethod->type ?? '');

        if ($type === 'card') {
            return [
                'card',
                $this->stringOrNull($paymentMethod->card->brand ?? null),
                $this->stringOrNull($paymentMethod->card->last4 ?? null),
            ];
        }

        if ($type === 'au_becs_debit') {
            return [
                'becs',
                $this->stringOrNull($paymentMethod->au_becs_debit->bsb_number ?? null) ?? 'bank',
                $this->stringOrNull($paymentMethod->au_becs_debit->last4 ?? null),
            ];
        }

        throw new \RuntimeException("Unsupported payment method type for brand payouts: {$type}");
    }

    /**
     * Check whether a capability on a v2 Account is in the 'active' state.
     *
     * Capability paths follow the v2 shape, e.g.
     *   configuration.merchant.capabilities.card_payments.status
     *   configuration.recipient.capabilities.stripe_balance.stripe_transfers.status
     */
    private static function capabilityIsActive(object $account, string $path): bool
    {
        return data_get($account, $path) === 'active';
    }

    /**
     * Pull the currently_due requirements list off a v2 Account, defaulting to empty.
     *
     * @return array<int, string>
     */
    private function extractRequirements(object $account): array
    {
        $list = data_get($account, 'requirements.currently_due');

        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_string'));
    }

    private function appendCheckoutSessionParam(string $url, string $param): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.$param.'={CHECKOUT_SESSION_ID}';
    }

    /**
     * Strip null/empty entries from a nested array so the Stripe SDK doesn't reject
     * the v2 Account create on optional fields we don't have values for yet.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterNullsRecursive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $filtered = $this->filterNullsRecursive($value);
                if ($filtered === []) {
                    unset($payload[$key]);
                } else {
                    $payload[$key] = $filtered;
                }
            } elseif ($value === null) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * Stripe Connect supported countries as of 2026. Source:
     * https://docs.stripe.com/connect/cross-border-payouts#supported-countries-and-currencies
     *
     * @var array<int, string>
     */
    private const STRIPE_CONNECT_SUPPORTED_COUNTRIES = [
        'AE', 'AT', 'AU', 'BE', 'BG', 'BR', 'CA', 'CH', 'CI', 'CR', 'CY', 'CZ', 'DE', 'DK', 'EE',
        'ES', 'FI', 'FR', 'GB', 'GH', 'GI', 'GR', 'HK', 'HR', 'HU', 'ID', 'IE', 'IN', 'IT', 'JP',
        'KE', 'KR', 'LI', 'LT', 'LU', 'LV', 'MT', 'MX', 'MY', 'NG', 'NL', 'NO', 'NZ', 'PE', 'PH',
        'PL', 'PT', 'RO', 'SA', 'SE', 'SG', 'SI', 'SK', 'TH', 'TR', 'US', 'UY', 'ZA',
    ];

    private function mapCountryCode(?string $code): string
    {
        $upper = strtoupper(trim((string) $code));

        if ($upper === '') {
            abort(422, 'A country is required for Stripe Connect onboarding. Please set your country on your profile before connecting.');
        }

        if (! in_array($upper, self::STRIPE_CONNECT_SUPPORTED_COUNTRIES, true)) {
            abort(422, "Country '{$upper}' is not supported by Stripe Connect. Please contact support.");
        }

        return $upper;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
