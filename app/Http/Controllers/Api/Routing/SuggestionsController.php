<?php

namespace App\Http\Controllers\Api\Routing;

use App\Catalog\CompiledCatalog;
use App\Catalog\Hosts;
use App\Catalog\LegacyPlatformMap;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\RoutingCapabilityGate;
use App\Routing\SuggestionApplier;
use App\Routing\SyncFindingsBridge;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Review-suggestions inbox (plan §2/§3): everything the router recognised
 * but would not act on alone.
 *
 * This is the counterweight to the suggestion gate. Moving below-threshold
 * matches from silent writes to confirmable suggestions is only an
 * improvement if the suggestions are somewhere a person can actually see and
 * resolve them — otherwise it is just silent dropping with extra steps.
 *
 * Three states surface here:
 *   proposed — recognised, below the auto-apply bar. "Is this yours?"
 *   blocked/conflict — recognised, but something already owns that slot.
 *                      Keep or Replace.
 *   blocked/other — recognised, but a gate or cap refuses. Explained, not
 *                   actionable.
 */
class SuggestionsController extends ApiController
{
    use ResolveCurrentUser;

    /** Store surfaces the probe runtime identifies (LinkProbeWorker's cascade). */
    private const PROBED_STORE_SURFACES = ['shopify.store', 'woocommerce.store', 'squarespace.store', 'bigcartel.store'];

    /** The one id the Google-listing suggestion answers to — it has no ledger row. */
    private const LISTING_ID = 'listing:opentable';

    /** Where its dismissal is remembered, since it is derived on every read. */
    private const LISTING_REF = 'opentable.reserve:google-listing';

    public function __construct(
        private readonly SuggestionApplier $applier,
        private readonly SyncFindingsBridge $bridge,
        private readonly OpenTableService $openTable,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $intents = DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->whereIn('state', ['proposed', 'blocked'])
            ->orderByDesc('first_seen_at')
            ->limit(100)
            ->get();

        $suggestions = $intents->map(function (object $intent) use ($user): array {
            $surface = CompiledCatalog::surface($intent->surface_key);
            $this->resolveSwapIncumbent($user, $intent, $surface);

            return [
                'id' => $intent->id,
                'state' => $intent->state,
                'blockReason' => $intent->block_reason,
                'surfaceKey' => $intent->surface_key,
                'displayName' => $surface['display_name'] ?? $intent->surface_key,
                'brandKey' => $surface['brand_key'] ?? null,
                'routingClass' => $intent->routing_class,
                'identifier' => $intent->identifier,
                'url' => $intent->canonical_url,
                'origin' => $intent->origin,
                'firstSeenAt' => $intent->first_seen_at,
                'conflictingConnectionId' => $intent->conflicting_connection_id,
                // What the user is being asked, in their words rather than the
                // reconciler's.
                'question' => $this->questionFor($intent, $surface),
                'actions' => $this->actionsFor($intent),
            ];
        })->all();

        // The inbox is the ONE place a found link is answered (owner,
        // 2026-08-19, retiring the per-platform synced modal). Two other
        // surfaces used to ask the same question in their own words:
        //
        //  · the legacy `payload.syncFindings` ledger, which the Instagram and
        //    Google Business modals rendered — folded here until those two
        //    harvests move onto the router and stop writing it;
        //  · the reservations smart-detect's standing OpenTable suggestion,
        //    which had an endpoint of its own the dashboard called separately.
        //
        // Folded at READ time, never written into the intent ledger: an intent
        // is a record of what the router decided, and neither of these came
        // from the router.
        // Deduped by surface: a scan that ran on the new pipeline records an
        // intent, and the same scan's legacy payload finding says the same
        // thing in the old vocabulary. The INTENT wins — it is the ledger with
        // a resolution path — and the payload row is dropped rather than shown
        // beside it as a second, identical question.
        $claimed = array_flip(array_column($suggestions, 'surfaceKey'));
        foreach ($this->bridge->payloadSuggestions($user) as $folded) {
            if (! isset($claimed[$folded['surfaceKey']])) {
                $claimed[$folded['surfaceKey']] = true;
                $suggestions[] = $folded;
            }
        }

        $listing = $this->googleListingSuggestion($user);
        if ($listing !== null) {
            $suggestions[] = $listing;
        }

        return $this->success(['suggestions' => $suggestions]);
    }

    /**
     * The OpenTable booking link Google lists for this place, as an ordinary
     * suggestion row.
     *
     * Suppressed once a reservation connection exists — it is an offer to FILL
     * an empty slot, and the reservations family is single-slot, so re-offering
     * it after the owner has one is asking a question they already answered.
     */
    private function googleListingSuggestion(User $user): ?array
    {
        $gb = $user->integrationConnections()->where('platform', 'google-business')->first();
        if ($gb === null) {
            return null;
        }

        $suggestion = $this->openTable->suggestionFromGoogleBusiness(GoogleBusinessPayload::fromArray($gb->payload)->toArray());
        $url = is_array($suggestion) ? $suggestion['url'] : null;
        if (! is_string($url) || $url === '') {
            return null;
        }

        $hasReservation = $user->integrationConnections()
            ->where('routing_class', 'reservations')
            ->exists();
        if ($hasReservation) {
            return null;
        }

        // Derived fresh on every read, so "Not now" needs somewhere durable to
        // live or the same row returns on the next one.
        $dismissed = DB::table('routing.item_tombstones')
            ->where('user_id', $user->id)
            ->where('source_ref', self::LISTING_REF)
            ->exists();
        if ($dismissed) {
            return null;
        }

        $name = is_string($suggestion['name'] ?? null) ? $suggestion['name'] : null;

        return [
            'id' => self::LISTING_ID,
            'state' => 'proposed',
            'blockReason' => null,
            'surfaceKey' => 'opentable.reserve',
            'displayName' => 'OpenTable',
            'brandKey' => 'opentable',
            'routingClass' => 'reservations',
            'identifier' => $name,
            'url' => $url,
            'origin' => 'google_business',
            'firstSeenAt' => null,
            'conflictingConnectionId' => null,
            'question' => 'Google lists this booking link for your place — use it for reservations?',
            'actions' => ['accept', 'dismiss'],
        ];
    }

    /** Accept a suggestion: apply the intent and create the connection. */
    public function accept(Request $request, string $intentId): JsonResponse
    {
        $user = $this->currentUser($request);

        if (str_starts_with($intentId, SyncFindingsBridge::PAYLOAD_ID_PREFIX)) {
            return $this->acceptPayloadFinding($user, $intentId);
        }
        if ($intentId === self::LISTING_ID) {
            return $this->acceptGoogleListing($user);
        }

        $intent = $this->findIntent($user->id, $intentId);

        if ($intent === null) {
            return $this->error('That suggestion is no longer available.', 404);
        }

        $surface = CompiledCatalog::surface($intent->surface_key);
        if ($surface === null) {
            return $this->error('That platform is no longer supported.', 422);
        }
        $this->resolveSwapIncumbent($user, $intent, $surface);

        // Shared with the synced-modal's "Change to" swap (SyncFindingsBridge)
        // — one writer for intent application, wherever the user answered.
        // A probed storefront (Shopify / WooCommerce / Squarespace / Big
        // Cartel, offered from a pasted link — owner ask 2026-08-18) needs
        // more than the bare connection the applier writes: the store
        // collection, name, logo, and the shop cap / tombstone checks the
        // seeder runs. So it goes back through StoreBrandSeeder — queue-only
        // (the probe answer is cached 12 h, so this is seconds) — which
        // places the connection, builds the store and settles this intent
        // itself (applied, or blocked with the reason the inbox already
        // renders). Nothing is written here that the seeder could contradict.
        if (in_array($intent->surface_key, self::PROBED_STORE_SURFACES, true) && is_string($intent->canonical_url ?? null) && $intent->canonical_url !== '') {
            CommerceProbeJob::dispatch((string) $user->id, (string) $intent->canonical_url, 'shop', acceptedIntentId: (string) $intent->id);

            return $this->success([
                'connectionId' => null,
                'surfaceKey' => $intent->surface_key,
                'displayName' => $surface['display_name'],
                'status' => 'pending',
            ], 202);
        }

        $connection = $this->applier->apply($user, $intent, $surface);

        return $this->success([
            'connectionId' => $connection->id,
            'surfaceKey' => $intent->surface_key,
            'displayName' => $surface['display_name'],
        ]);
    }

    /**
     * Dismiss a suggestion. Permanent by design: re-asking a question the
     * user already answered is how an inbox becomes noise people ignore.
     */
    public function dismiss(Request $request, string $intentId): JsonResponse
    {
        $user = $this->currentUser($request);

        // A folded row settles in the ledger it came from — the legacy finding
        // is marked dismissed in place; the Google listing has no ledger, so
        // its refusal is a tombstone (the only thing that stops the listing
        // re-offering the same link on every read).
        if (str_starts_with($intentId, SyncFindingsBridge::PAYLOAD_ID_PREFIX)) {
            $located = $this->bridge->locatePayloadFinding($user, $intentId);
            if ($located === null) {
                return $this->error('That suggestion is no longer available.', 404);
            }
            $this->bridge->settlePayloadFinding($located['connection'], $located['index'], 'dismissed');

            return $this->success(['dismissed' => true]);
        }

        if ($intentId === self::LISTING_ID) {
            $this->tombstone($user, self::LISTING_REF, 'listing suggestion dismissed');

            return $this->success(['dismissed' => true]);
        }

        $intent = $this->findIntent($user->id, $intentId);

        if ($intent === null) {
            return $this->error('That suggestion is no longer available.', 404);
        }

        DB::table('routing.source_intents')->where('id', $intent->id)->update([
            'state' => 'dismissed',
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        // A dismissal is also an instruction not to re-import this: without
        // the tombstone the next harvest would simply propose it again.
        $this->tombstone($user, $intent->surface_key.':'.$intent->identifier, 'suggestion dismissed');

        // M-9 follow-up (critic, 2026-08-21): a probe-placed tenant store's
        // identifier is the storefront's NUMERIC id, but the pure projector
        // keys the same host by its TENANT LABEL — so a tombstone written
        // under only the numeric id never matches the projector-side check
        // and every re-scan re-probes the refused store. Record the label
        // alias too, derived from the intent's own canonical host.
        $host = strtolower((string) parse_url((string) $intent->canonical_url, PHP_URL_HOST));
        foreach (Hosts::SHOP_TENANT_SUFFIXES as $suffix) {
            if (str_ends_with($host, '.'.$suffix)) {
                $label = substr($host, 0, -1 * (strlen($suffix) + 1));
                $label = str_starts_with($label, 'www.') ? substr($label, 4) : $label;
                if ($label !== '' && $label !== (string) $intent->identifier) {
                    $this->tombstone($user, $intent->surface_key.':'.$label, 'suggestion dismissed');
                }
                break;
            }
        }

        return $this->success(['dismissed' => true]);
    }

    /**
     * Swap in a link the legacy ledger recorded as a conflict.
     *
     * The write is the autosync's own applyFinding — the recipe the finding
     * carries names rows on OTHER platforms to remove, which only the service
     * that wrote it knows how to honour. This controller owns WHERE the
     * question is asked, not how the swap is performed.
     */
    private function acceptPayloadFinding(User $user, string $suggestionId): JsonResponse
    {
        $located = $this->bridge->locatePayloadFinding($user, $suggestionId);
        if ($located === null) {
            return $this->error('That suggestion is no longer available.', 404);
        }

        $autoSync = $located['holder'] === 'instagram'
            ? app(InstagramAutoSync::class)
            : app(GoogleBusinessAutoSync::class);

        // applyFinding stays OUTSIDE the platform lock. The load-bearing reason
        // is ORDERING: it takes its own booking/reservations XOR lock
        // internally, and this call fully releasing first is what keeps that
        // ordering acyclic (§9.4 of the U1 plan). Do not move it inside the
        // closure below.
        //
        // SCALE-1's second reason is now historical: it dispatches
        // InstagramConnectJob, which ran inline (~110s) — long enough for a
        // 10s-TTL lock to expire mid-flight — only under QUEUE_CONNECTION=sync.
        // Both envs are on `redis` as of 2026-08-25 (dev verified running
        // Horizon, 1 supervisor, 0 queued), so the job no longer holds the
        // request. Keep the ordering rule regardless; it does not depend on the
        // queue driver.
        //
        // False means a contended XOR lock: nothing was removed and nothing
        // written, so the finding must NOT be settled — marking it done for a
        // change that never happened is how a swap silently loses a connection.
        if (! $autoSync->applyFinding((string) $user->id, $located['finding'])) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        // The settle IS the contended span: GoogleBusinessEnrichJob::persist()
        // rewrites the same payload under the same key.
        try {
            Cache::lock(CacheKeyGenerator::platformConnectionLock($located['holder'], (string) $user->id), 10)
                ->block(5, fn () => $this->bridge->settlePayloadFinding($located['connection'], $located['index'], 'seeded'));
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        return $this->success([
            'connectionId' => null,
            'surfaceKey' => LegacyPlatformMap::surfaceFor((string) $located['finding']['platform']) ?? $located['finding']['platform'],
            'displayName' => (string) ($located['finding']['label'] ?? $located['finding']['platform']),
        ]);
    }

    /** Use the OpenTable link Google lists for this place. */
    private function acceptGoogleListing(User $user): JsonResponse
    {
        $listing = $this->googleListingSuggestion($user);
        if ($listing === null) {
            return $this->error('That suggestion is no longer available.', 404);
        }

        $denied = RoutingCapabilityGate::denialFor($user, 'reservations');
        if ($denied !== null) {
            throw new AuthorizationException($denied);
        }

        $connection = app(SuggestionApplier::class)->applyDirect(
            $user,
            'opentable.reserve',
            'reservations',
            'opentable',
            (string) $listing['url'],
        );

        return $this->success([
            'connectionId' => $connection->id,
            'surfaceKey' => 'opentable.reserve',
            'displayName' => 'OpenTable',
        ]);
    }

    /** "Do not offer this again" — the only thing that stops a standing source re-proposing. */
    private function tombstone(User $user, string $sourceRef, string $reason): void
    {
        DB::table('routing.item_tombstones')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'source_ref' => $sourceRef,
            'scope' => 'this_source',
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Settle a cap-blocked intent against the cap AS IT IS NOW.
     *
     * A cap-blocked intent on a SINGLE-account surface names the one row a
     * Swap would replace (SourceReconciler::soleIncumbentFor writes it at
     * reconcile time since 2026-08-19). Intents recorded before that carry
     * null; resolving it here at read time gives them the same Swap, and
     * persisting it keeps accept() and the inbox in agreement. A cap that is
     * no longer reached lifts the block entirely. A multi-account surface
     * still at its cap is left alone — there is no one row a swap could
     * mean. Mutates $intent in place.
     *
     * @param  array<string, mixed>|null  $surface
     */
    private function resolveSwapIncumbent(User $user, object $intent, ?array $surface): void
    {
        if ($intent->block_reason !== 'cap_reached'
            || $intent->conflicting_connection_id !== null
            || $surface === null) {
            return;
        }

        $others = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $intent->surface_key)
            ->where('resource_id', '!=', (string) $intent->identifier)
            ->orderBy('created_at')
            ->pluck('id');
        $max = max(1, (int) ($surface['max_accounts'] ?? 1));

        // The cap has moved under a standing intent — the catalog widened
        // (2026-08-19: content and events surfaces went 1 → 5) or the owner
        // disconnected one — so it is no longer blocked at all: back to a
        // plain proposal, asked once as "add this?".
        if ($others->count() < $max) {
            DB::table('routing.source_intents')->where('id', $intent->id)->update([
                'state' => 'proposed',
                'block_reason' => null,
                'updated_at' => now(),
            ]);
            $intent->state = 'proposed';
            $intent->block_reason = null;

            return;
        }

        if ($max > 1) {
            return;
        }

        $incumbent = $others->first();
        if ($incumbent === null) {
            return;
        }

        DB::table('routing.source_intents')->where('id', $intent->id)->update([
            'conflicting_connection_id' => $incumbent,
            'updated_at' => now(),
        ]);
        $intent->conflicting_connection_id = $incumbent;
    }

    private function findIntent(string $userId, string $intentId): ?object
    {
        return DB::table('routing.source_intents')
            ->where('id', $intentId)
            ->where('user_id', $userId)
            ->whereIn('state', ['proposed', 'blocked'])
            ->first();
    }

    /** @param array<string, mixed>|null $surface */
    private function questionFor(object $intent, ?array $surface): string
    {
        $name = $surface['display_name'] ?? 'this platform';

        return match ($intent->block_reason) {
            'conflict' => "You already have a {$intent->routing_class} link. Use this {$name} one instead?",
            // A single-account surface at its cap names its one incumbent
            // (SourceReconciler::soleIncumbentFor), so the ask is a swap;
            // a multi-account surface at its cap has no one row to swap.
            // "{$name} connected", not "a {$name}" — the article broke on
            // vowel-initial brands ("a Instagram", "a Eventbrite").
            'cap_reached' => $intent->conflicting_connection_id !== null
                ? "You already have {$name} connected — swap it for this one?"
                : "You've reached the limit for {$name} accounts.",
            'gate' => "{$name} isn't available on your account type.",
            // The user already answered this one and the build failed. Saying
            // so beats re-asking "Is this your Shopify store?", which is what
            // the default branch below would do — the same question, with no
            // hint that anything was attempted.
            'unservable' => "We couldn't reach this {$name}. Try again?",
            'below_threshold' => "Is this your {$name}?",
            default => "Add this {$name} link?",
        };
    }

    /** @return list<string> */
    private function actionsFor(object $intent): array
    {
        return match ($intent->block_reason) {
            'conflict' => ['replace', 'dismiss'],
            'cap_reached' => $intent->conflicting_connection_id !== null ? ['replace', 'dismiss'] : ['dismiss'],
            'gate' => ['dismiss'],
            // Retryable on purpose: an unreachable storefront is usually
            // weather, not a verdict.
            'unservable' => ['accept', 'dismiss'],
            default => ['accept', 'dismiss'],
        };
    }
}
