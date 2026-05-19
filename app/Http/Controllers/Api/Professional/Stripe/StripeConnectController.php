<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Stripe\CreatePaymentMethodSetupRequest;
use App\Http\Requests\Api\Professional\Stripe\ExportsRequest;
use App\Http\Requests\Api\Professional\Stripe\OnboardRequest;
use App\Http\Requests\Api\Professional\Stripe\PayoutsRequest;
use App\Http\Requests\Api\Professional\Stripe\SyncPaymentMethodSessionRequest;
use App\Http\Requests\Api\Professional\Stripe\TransactionsRequest;
use App\Http\Resources\Stripe\TransactionResource;
use App\Models\Commerce\CommissionPayout;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\ExportService;
use App\Services\Stripe\StripeBalanceService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeTransactionFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

// V2: Stripe Connect Express onboarding, brand payment method, and payout history. Required for the brand-as-merchant-of-record commission flow.
class StripeConnectController extends Controller
{
    public function __construct(
        private readonly StripeConnectService $connectService,
        private readonly CommissionPayoutService $payoutService,
        private readonly StripeTransactionFetcher $transactionFetcher,
        private readonly StripeBalanceService $balanceService,
        private readonly ExportService $exportService,
        private readonly CacheLockService $cacheLock,
    ) {}

    /**
     * GET /stripe/status
     *
     * Returns the brand/affiliate's Stripe Connect state for the dashboard. Shape:
     *   {
     *     "connect": { status, stripe_connect_account_id, card_payments_active,
     *                  stripe_transfers_active, requirements: [...] },
     *     "has_payment_method": bool,
     *     "payout_method": "card" | "becs" | null,
     *     "masked_card": { "brand": "...", "last4": "..." } | null
     *   }
     *
     * The dropped-column fields (stripe_customer_id, charges_enabled, payouts_enabled,
     * details_submitted) are gone — the frontend reads card_payments_active /
     * stripe_transfers_active off `connect` for the dual-capability state, and reads
     * the saved-PM display fields off the top-level keys.
     */
    public function status(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        // ?fresh=1 forces a live Stripe round-trip — used immediately after the
        // Stripe Connect onboarding redirect so the dashboard never renders a
        // pre-onboarding cache hit while the v2.core.account.* webhook is in flight.
        if ($request->boolean('fresh') && $pro->stripe_connect_account_id) {
            StripeConnectService::forgetStatusCache($pro->stripe_connect_account_id);
        }

        $connectStatus = $this->connectService->syncAccountStatus($pro);
        $pro->refresh();

        $hasPaymentMethod = $this->connectService->brandHasPaymentMethod($pro);
        $maskedCard = $hasPaymentMethod && $pro->stripe_payment_method_last4
            ? [
                'brand' => $pro->stripe_payment_method_brand,
                'last4' => $pro->stripe_payment_method_last4,
            ]
            : null;

        // Phase 4 — surface both type-specific PMs + preference so the frontend can
        // render PaymentMethodsManager (two side-by-side cards + primary toggle).
        // Legacy `payout_method` + `masked_card` are preserved for back-compat.
        $cardMasked = $pro->stripe_card_payment_method_id ? [
            'brand' => $pro->stripe_card_brand,
            'last4' => $pro->stripe_card_last4,
        ] : null;
        $becsMasked = $pro->stripe_becs_payment_method_id ? [
            'bsb' => $pro->stripe_becs_bsb,
            'last4' => $pro->stripe_becs_last4,
        ] : null;

        return response()->json([
            'connect' => $connectStatus,
            'has_payment_method' => $hasPaymentMethod,
            'payout_method' => $pro->payout_method,
            'masked_card' => $maskedCard,
            'card_payment_method' => $cardMasked,
            'becs_payment_method' => $becsMasked,
            'preferred_payout_method' => $pro->preferred_payout_method,
        ]);
    }

    /**
     * POST /stripe/connect/onboard
     * Creates a Connect Express account and returns the onboarding URL.
     * Used by professionals/influencers to receive payouts.
     */
    public function onboard(OnboardRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $url = $this->connectService->createOnboardingLink(
            $pro,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );

        return response()->json(['onboarding_url' => $url]);
    }

    /**
     * POST /stripe/connect/dashboard
     * Returns a Stripe Express dashboard login link.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $url = $this->connectService->createDashboardLink($pro);

        if (! $url) {
            $reason = in_array($pro->stripe_connect_status, ['active', 'restricted'], true)
                ? 'Could not generate a Stripe Dashboard link right now. Please try again in a moment.'
                : 'Complete Stripe onboarding before opening the dashboard.';

            return response()->json(['error' => $reason], 422);
        }

        return response()->json(['dashboard_url' => $url]);
    }

    /**
     * POST /stripe/connect/disconnect
     * Disconnects the Connect account from the platform.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        if (! $pro->stripe_connect_account_id) {
            return response()->json(['error' => 'No Stripe Connect account to disconnect.'], 422);
        }

        $this->connectService->disconnectAccount($pro);

        return response()->json(['status' => 'not_connected']);
    }

    /**
     * POST /stripe/payment-method/setup-checkout
     *
     * Creates a hosted Stripe Checkout setup session that saves a CARD to the brand's
     * v2 Account. Used as the off-session payment source for destination-charge
     * commission payouts. Brand must already have completed Connect onboarding.
     */
    public function createPaymentMethodCheckoutSession(CreatePaymentMethodSetupRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $result = $this->connectService->createBrandPaymentMethodSetupSession(
                $pro,
                $request->input('success_url'),
                $request->input('cancel_url'),
            );

            return response()->json($result);
        } catch (\Stripe\Exception\ApiErrorException|\RuntimeException $e) {
            report($e);
            Log::error('Stripe payment method setup session creation failed', [
                'brand_professional_id' => $pro->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /stripe/payment-method/setup-becs
     *
     * Creates a hosted Stripe Checkout setup session that saves a BECS Direct Debit
     * mandate to the brand's v2 Account. Stripe Checkout collects the BSB +
     * account number and renders the mandate acceptance UI automatically.
     *
     * BECS-specific risks: T+2 settlement timing, 7-year dispute window. Platform
     * bears the loss with losses_collector=application. The frontend should explain
     * these tradeoffs in the picker UI before sending the user here.
     */
    public function createBecsCheckoutSession(CreatePaymentMethodSetupRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $result = $this->connectService->createBrandBecsSetupSession(
                $pro,
                $request->input('success_url'),
                $request->input('cancel_url'),
            );

            return response()->json($result);
        } catch (\Stripe\Exception\ApiErrorException|\RuntimeException $e) {
            report($e);
            Log::error('Stripe BECS setup session failed', [
                'brand_professional_id' => $pro->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /stripe/payment-method/sync-session
     * Syncs the saved payment method from a completed Checkout setup session on the
     * brand's v2 Account. Handles both card and BECS — payout_method is derived from
     * the PM type Stripe returns.
     */
    public function syncPaymentMethodSession(SyncPaymentMethodSessionRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $result = $this->connectService->syncBrandPaymentMethodFromCheckoutSession(
                $pro,
                $request->input('session_id'),
            );

            return response()->json([
                'status' => 'saved',
                ...$result,
            ]);
        } catch (\Stripe\Exception\ApiErrorException|\RuntimeException $e) {
            report($e);
            Log::error('Stripe payment method sync failed', [
                'brand_professional_id' => $pro->id,
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /stripe/payment-method[?type=card|becs]
     * Detaches the brand's saved PaymentMethod from their v2 Account and clears the
     * local cache columns. Phase 4: pass ?type=card|becs to remove only that PM and
     * keep the other one. Without ?type, removes the primary (preferred or legacy).
     */
    public function removePaymentMethod(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        $type = $request->query('type');
        if ($type !== null && ! in_array($type, ['card', 'becs'], true)) {
            return response()->json(['error' => 'invalid_type'], 422);
        }

        $this->connectService->removeBrandPaymentMethod($pro, $type);

        return response()->json(['status' => 'removed']);
    }

    /**
     * PUT /stripe/payment-method/preference
     * Sets the brand's preferred payout method (card | becs). The chosen type must
     * already have a PM ID on file — controller catches the service exception and
     * returns 422 with the underlying message.
     */
    public function setPaymentMethodPreference(\App\Http\Requests\Api\Professional\Stripe\PaymentPreferenceRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $this->connectService->setBrandPreferredPayoutMethod($pro, (string) $request->input('method'));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'updated']);
    }

    /**
     * GET /stripe/payouts
     * Lists payout history for the professional.
     *
     * TODO: expose commission ledger entries as a sibling endpoint so brands
     * can reconcile "why is this payout $X?" against individual commission
     * rows. The CommissionMovement model, commerce.commission_movements
     * table, and CommissionPayoutService already exist — the only thing
     * missing is the HTTP layer (controller method + route + resource + test).
     * Deferred until a real brand asks; when promoting, decide between:
     *   a) nested under a specific payout ID (GET /stripe/payouts/{id}/entries)
     *      — simplest, answers the "reconcile this one payout" question.
     *   b) a top-level list with filter params (GET /stripe/commissions?
     *      payout_id=&date_from=&date_to=&status=) — more flexible but more
     *      surface area to validate.
     * Option (a) is probably the right first move; (b) can grow from it.
     */
    public function payouts(PayoutsRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        $role = $request->input('role');

        // Gate first — CommissionPolicy::viewOwnPayouts rejects cross-role calls
        // (affiliate using ?role=brand, or vice versa) with a clean 403 instead of
        // leaking an empty 200. The skeleton carries the role-appropriate id only.
        $skeleton = new CommissionPayout;
        $skeleton->forceFill($role === 'brand'
            ? ['brand_professional_id' => $pro->id]
            : ['affiliate_professional_id' => $pro->id]);
        Gate::forUser($pro)->authorize('viewOwnPayouts', $skeleton);

        $query = CommissionPayout::query()
            ->with(['brandProfessional:id,display_name,handle', 'affiliateProfessional:id,display_name,handle']);

        if ($role === 'brand') {
            $query->where('brand_professional_id', $pro->id);
        } else {
            $query->where('affiliate_professional_id', $pro->id);
        }

        // Filters
        if ($dateFrom = $request->input('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            // Inclusive of the entire `date_to` day — the frontend sends YYYY-MM-DD.
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }
        $statuses = array_values(array_filter((array) $request->input('status', [])));
        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        // Cursor pagination — base64-encoded {t,id} of the last row from the previous page.
        // Order by (created_at DESC, id DESC) so cursor comparison stays total across rows
        // with identical timestamps.
        if ($cursor = $request->input('cursor')) {
            $decoded = json_decode(base64_decode((string) $cursor), true);
            if (is_array($decoded) && isset($decoded['t'], $decoded['id'])) {
                $query->where(function ($q) use ($decoded): void {
                    $q->where('created_at', '<', $decoded['t'])
                        ->orWhere(function ($q2) use ($decoded): void {
                            $q2->where('created_at', $decoded['t'])
                                ->where('id', '<', $decoded['id']);
                        });
                });
            }
        }

        $limit = (int) $request->input('limit', 25);
        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit);

        $nextCursor = null;
        if ($hasMore && $visible->isNotEmpty()) {
            $last = $visible->last();
            // Use toDateTimeString() (not ISO) so cursor format matches the column's
            // stored representation — keeps lexicographic comparisons sound on test
            // backends that store created_at as TEXT.
            $nextCursor = base64_encode((string) json_encode([
                't' => $last->created_at?->toDateTimeString(),
                'id' => (string) $last->id,
            ]));
        }

        $payouts = $visible->map(fn ($p) => $this->shapePayoutListRow($p));

        $summary = $this->payoutService->getPayoutSummary($pro);

        return response()->json([
            'payouts' => $payouts->values(),
            'summary' => $summary,
            'pagination' => [
                'cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * GET /stripe/payouts/{payoutId}
     *
     * Drill-down for a single commission payout. Returns the payout plus the linked
     * orders from commerce.orders (where payout_id = {payoutId}) so brands can answer
     * "why is this payout $X?" without leaving the Partna dashboard.
     *
     * Cross-tenant access returns 404 (per CLAUDE.md "403 vs 404 standard") — neither
     * the brand nor the affiliate of someone else's payout should be told it exists.
     */
    public function payoutDetail(Request $request, string $payoutId): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        // findOrFail throws ModelNotFoundException → rendered as 404 "Resource not found"
        // by bootstrap/app.php. CommissionPolicy::view's denyAsNotFound() path renders the
        // same shape via HttpException(404) — both 404s match envelope. NotFoundHttpException
        // (what abort(404) throws) would render "Endpoint not found", which is wrong here.
        $payout = CommissionPayout::with([
            'brandProfessional:id,display_name,handle',
            'affiliateProfessional:id,display_name,handle',
        ])->findOrFail($payoutId);

        Gate::forUser($pro)->authorize('view', $payout);

        // Bound the in-memory order set so a high-volume payout (hundreds of orders)
        // can't materialise an unbounded result on the request thread. Frontend uses
        // `has_more` to decide between a "load more" affordance and pointing the
        // user at the export endpoint (which is also capped at 500 in ExportService).
        $limit = (int) config('partna.payouts.detail_orders_limit', 500);
        $orders = \App\Models\Commerce\Order::query()
            ->where('payout_id', $payoutId)
            ->orderBy('occurred_at')
            ->limit($limit + 1)
            ->get();

        $hasMore = $orders->count() > $limit;
        if ($hasMore) {
            $orders = $orders->take($limit);
        }

        return response()->json([
            'payout' => $this->shapePayoutListRow($payout),
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id,
                'shopify_order_id' => $o->shopify_order_id,
                'status' => $o->status,
                'gross_cents' => (int) $o->gross_cents,
                'commission_cents' => (int) $o->commission_cents,
                'commission_rate' => (float) $o->commission_rate,
                'refund_cents' => (int) ($o->refund_cents ?? 0),
                'net_cents' => (int) ($o->net_cents ?? 0),
                'currency_code' => $o->currency_code,
                'occurred_at' => $o->occurred_at?->toIso8601String(),
            ])->values(),
            'has_more' => $hasMore,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shapePayoutListRow(CommissionPayout $p): array
    {
        return [
            'id' => $p->id,
            'status' => $p->status,
            'gross_commission_cents' => $p->gross_commission_cents,
            'platform_fee_cents' => $p->platform_fee_cents,
            'net_payout_cents' => $p->net_payout_cents,
            'currency_code' => $p->currency_code,
            'ledger_entry_count' => $p->ledger_entry_count,
            'failure_reason' => $p->failure_reason,
            'payment_intent_id' => $p->payment_intent_id ?? null,
            'eligible_after' => $p->eligible_after?->toIso8601String(),
            'processed_at' => $p->processed_at?->toIso8601String(),
            'void_at' => $p->void_at?->toIso8601String(),
            'transfer_completed_at' => $p->transfer_completed_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'brand' => $p->brandProfessional ? [
                'id' => $p->brandProfessional->id,
                'name' => $p->brandProfessional->display_name,
                'handle' => $p->brandProfessional->handle,
            ] : null,
            'affiliate' => $p->affiliateProfessional ? [
                'id' => $p->affiliateProfessional->id,
                'name' => $p->affiliateProfessional->display_name,
                'handle' => $p->affiliateProfessional->handle,
            ] : null,
        ];
    }

    /**
     * GET /stripe/transactions
     *
     * Returns a unified list of Stripe-side transactions scoped to the caller's role:
     *   brand     → charges + refunds across their commission payouts
     *   affiliate → transfers + reversals across their incoming commission
     *
     * Data is pulled live from Stripe per request (no local mirror); a 60s
     * CacheLockService wrap keyed by role + professional + filter hash absorbs
     * tab-flipping and rapid filter changes without blowing through Stripe's
     * 100 req/s budget. Cursor pagination is supported via the request's
     * `limit` param; richer cursor semantics come in Phase 2 alongside the
     * payouts endpoint extension.
     */
    public function transactions(TransactionsRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        $role = (string) $request->input('role');

        $skeleton = new CommissionPayout;
        $skeleton->forceFill($role === 'brand'
            ? ['brand_professional_id' => $pro->id]
            : ['affiliate_professional_id' => $pro->id]);
        Gate::forUser($pro)->authorize('viewOwnTransactions', $skeleton);

        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'type' => $request->input('type', 'all'),
            'limit' => $request->input('limit', 25),
        ];

        $cacheKey = sprintf(
            'stripe:txns:%s:%s:%s',
            $role,
            $pro->id,
            hash('xxh64', json_encode($filters) ?: ''),
        );

        $rows = $this->cacheLock->rememberLocked(
            $cacheKey,
            60,
            fn () => $role === 'brand'
                ? $this->transactionFetcher->forBrand($pro, $filters)
                : $this->transactionFetcher->forAffiliate($pro, $filters),
        );

        return response()->json([
            'transactions' => TransactionResource::collection($rows),
        ]);
    }

    /**
     * GET /stripe/balance — affiliate only.
     *
     * Returns the affiliate's available + pending balance (AUD, cents) plus the
     * auto-payout schedule from their connected account. Cached 60s per affiliate.
     */
    public function balance(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $cacheKey = 'stripe:balance:'.$pro->id;
        $payload = $this->cacheLock->rememberLocked($cacheKey, 60, function () use ($pro) {
            return [
                'balance' => $this->balanceService->forAffiliate($pro),
                'schedule' => $this->balanceService->payoutScheduleFor($pro),
            ];
        });

        return response()->json($payload);
    }

    /**
     * GET /stripe/payouts/upcoming — affiliate only.
     *
     * Returns Stripe Payouts on the connected account currently in pending or
     * in_transit state — the "money on the way" view. Cached 60s.
     */
    public function upcomingPayouts(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $cacheKey = 'stripe:upcoming_payouts:'.$pro->id;
        $rows = $this->cacheLock->rememberLocked(
            $cacheKey,
            60,
            fn () => $this->balanceService->upcomingFor($pro),
        );

        return response()->json([
            'payouts' => $rows,
        ]);
    }

    /**
     * GET /stripe/exports/{type}.{format}
     *
     * Streaming export for the Documents tab. type ∈ {transactions, payouts,
     * detailed-commissions, eofy}; format ∈ {csv, xlsx}. role + filters in the
     * query string scope the data to the caller. Cross-role calls are rejected
     * via the same skeleton pattern used elsewhere.
     */
    public function export(ExportsRequest $request, string $type, string $format)
    {
        if (! in_array($type, ['payouts', 'detailed-commissions', 'eofy'], true)) {
            return response()->json(['error' => 'invalid_type'], 422);
        }
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return response()->json(['error' => 'invalid_format'], 422);
        }

        $pro = $request->attributes->get('professional');
        $role = (string) $request->input('role');

        $skeleton = new CommissionPayout;
        $skeleton->forceFill($role === 'brand'
            ? ['brand_professional_id' => $pro->id]
            : ['affiliate_professional_id' => $pro->id]);
        Gate::forUser($pro)->authorize('viewOwnPayouts', $skeleton);

        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'status' => $request->input('status', []),
            'fy' => $request->input('fy'),
        ];

        return match ($type) {
            'payouts' => $this->exportService->exportPayouts($pro, $role, $format, $filters),
            'detailed-commissions' => $this->exportService->exportDetailedCommissions($pro, $role, $format, $filters),
            'eofy' => $this->exportService->exportEofy($pro, $role, $format, $filters),
            default => abort(404),
        };
    }
}
