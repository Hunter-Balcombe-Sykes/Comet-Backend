<?php

namespace App\Routing;

use App\Catalog\CatalogIntegrityCheck;
use App\Catalog\CompiledCatalog;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY component that reads caller context. Turns a pure Projection into
 * a Placement by asking, in order:
 *
 *   1. is this surface servable at all (catalog integrity)?
 *   2. is it tombstoned for this user (they already said no)?
 *   3. Gate 1 — AccountCapabilities (the sanctioned capability read)
 *   4. Gate 2 — the routing gate matrix carried from the 2026-07-25 plan
 *   5. confidence + margin against RoutingPolicy
 *
 * Both gates are evaluated HERE, before anything is written (plan §2 crowd
 * correction: nothing moves to display time). A denied gate never drops the
 * link — it becomes a Note, so the user still gets their link, just not as a
 * connected platform.
 */
class PlacementPolicy
{
    /**
     * Import-run-scoped tombstone memo (SCALE-20). Single entry, replaced
     * never accumulated: this policy is not container-bound as a singleton
     * (nothing in app/Providers binds it), but a caller that resolves it once
     * and reuses it across import runs must not grow this into an unbounded
     * per-run cache.
     *
     * @var array{key: string, at: float, refs: array<string, int>}|null
     */
    private ?array $tombstoneMemo = null;

    /**
     * Seconds a primed memo may serve before re-reading the table. Bounds the
     * concurrent-dismiss race (see isTombstoned()'s docblock) to a window
     * comparable to the pre-memo per-link EXISTS, while keeping the batch
     * query savings SCALE-20 bought.
     */
    private const TOMBSTONE_MEMO_TTL_SECONDS = 5;

    /** Test seam: expire the primed memo without sleeping. */
    public function agePrimedMemoForTest(): void
    {
        if ($this->tombstoneMemo !== null) {
            $this->tombstoneMemo['at'] = 0.0;
        }
    }

    public function decide(Projection $projection, RoutingContext $context): Placement
    {
        if (! $projection->matched()) {
            // Unmatched is not one bucket. A healthy URL we simply don't
            // recognise is still a link the user wants on their page (Note —
            // kept, never dropped). A URL the canonicaliser refused (malformed,
            // own-infra, shortener, confusable host…) can never become a link
            // card at all, so it must keep blocking — the "unroutable" case
            // Verdict::Reject documents.
            if (in_array($projection->reason, ['unknown-domain', 'no-rule-matched'], true)) {
                return new Placement(Verdict::Note, null, null, $projection->reason, 'unrecognised link');
            }

            return Placement::reject($projection->reason ?? 'unroutable');
        }

        $surfaceKey = $projection->surfaceKey;
        $surface = CompiledCatalog::surface($surfaceKey);

        if ($surface === null || ! CatalogIntegrityCheck::isServable($surfaceKey)) {
            return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'unservable', 'surface not servable by this build');
        }

        $routingClass = (string) $surface['routing_class'];

        if (RoutingPolicy::isIgnored($routingClass)) {
            return Placement::reject('ignored', $surfaceKey);
        }

        if ($surface['lifecycle'] === 'retired') {
            return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'retired', 'brand no longer operating');
        }

        // A user's removal is permanent against RE-IMPORTS (C8): a scan or
        // harvest must never resurrect what they deleted. An explicit paste
        // is the opposite of a re-import — the user directly asking for the
        // link back — so a direct request wins over the tombstone (owner
        // decision, 2026-07-28). The reconciler deletes the superseded
        // refusal when the re-add actually applies.
        if ($context->user !== null && ! $context->isDirectRequest() && $this->isTombstoned($context->user, $projection, $context)) {
            return Placement::reject('tombstoned', $surfaceKey);
        }

        // ── Gate 1: capabilities ────────────────────────────────────────────
        // Pre-account builds have no user and therefore no capability set;
        // Decision 7 (carried) says above-threshold auto-applies for them.
        if ($context->user !== null) {
            $denied = $this->capabilityDenial($context->user, $routingClass);
            if ($denied !== null) {
                return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'gate', $denied);
            }
        }

        // ── Gate 2: the routing gate matrix ─────────────────────────────────
        // (social/events/shop/content: everyone; booking/reservations/ordering
        // as per capabilities above — this layer additionally refuses classes
        // that need an account at all.)
        if ($context->user === null && in_array($routingClass, ['booking', 'reservations', 'ordering'], true)) {
            // A cold build can still RECORD the link; it just cannot own a CTA
            // before anyone has claimed the site.
            return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'gate', 'action classes need a claimed account');
        }

        // ── Confidence ──────────────────────────────────────────────────────
        $confidence = $projection->confidence;
        if (! $context->isDirectRequest()) {
            $confidence -= RoutingPolicy::indirectPenalty();
        }

        $auto = RoutingPolicy::autoThreshold($routingClass);
        $suggest = RoutingPolicy::suggestThreshold($routingClass);

        if ($confidence >= $auto && $projection->margin >= RoutingPolicy::minMargin()) {
            return new Placement(Verdict::Place, $surfaceKey, $projection->identifier);
        }

        if ($confidence >= $suggest) {
            // Harvest maximisation (owner ruling 2026-08-18): on any indirect
            // origin the suggest band auto-applies. Pre-claim there is no
            // inbox to see a suggestion; post-claim it is friction on a link
            // the user demonstrably published. Margin still gates — a
            // too-close projection stays Choose because the doubt there is
            // WHICH surface, not whether. Direct paste keeps the confirm
            // flow: it is a wire contract with the dashboard preview.
            if (! $context->isDirectRequest() && $projection->margin >= RoutingPolicy::minMargin()) {
                return new Placement(Verdict::Place, $surfaceKey, $projection->identifier);
            }

            $why = $confidence < $auto
                ? 'below auto-apply threshold'
                : 'two rules matched too closely to decide automatically';

            return new Placement(Verdict::Choose, $surfaceKey, $projection->identifier, 'below_threshold', $why);
        }

        return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'below_threshold', 'kept as a link');
    }

    /** The arms live in RoutingCapabilityGate, shared with SuggestionApplier (#DRIFT-1). */
    private function capabilityDenial(User $user, string $routingClass): ?string
    {
        return RoutingCapabilityGate::denialFor($user, $routingClass);
    }

    /**
     * A tombstone is keyed by what the user refused: either this exact source
     * item (surface:identifier) or the whole surface. `scope` widens the
     * refusal across sources at the ITEM level, which needs identity
     * resolution — that lookup joins in at P4; here, matching the ref itself
     * is the whole rule.
     *
     * SCALE-20: a paste (no import run) keeps the original one-EXISTS-call
     * shape — no behaviour change on the request path. A batch import calls
     * decide() once per harvested link, and that was one EXISTS query per
     * link; this branch instead pulls every tombstone for the user ONCE per
     * import run and answers every subsequent link in that run from memory.
     *
     * Two call sites write routing.item_tombstones:
     *   - SourceReconciler::applyIntent()'s DELETE (SourceReconciler.php
     *     ~:265-270), gated on $context->isDirectRequest() (origin ===
     *     'paste') — neither WebsiteImporter nor LinkInBioImporter ever
     *     build a context with that origin, so this one cannot fire mid-run.
     *   - SuggestionsController::dismiss()'s insertOrIgnore
     *     (SuggestionsController.php:120-127) — UNGATED, no RoutingContext
     *     involved at all, reachable any time from its own HTTP endpoint.
     *
     * The memo is frozen at prefetch, so it cannot see a dismissal that
     * lands after the prefetch but before the run ends. That window is real,
     * not theoretical: SourceReconciler::reconcile() commits each link's
     * intent in its own transaction (SourceReconciler.php:90), so an earlier
     * link's suggestion in an in-progress import() is already committed and
     * dismissible while the run continues; and WebsiteImporter dedupes by
     * raw href text, not canonical target (WebsiteImporter.php:70-74), so
     * two different hrefs on one page that canonicalise to the same
     * surface/identifier survive dedup as two separate decide() calls in the
     * same run. Concretely: link A commits a suggestion early in a run: the
     * user dismisses it in a concurrent request, which inserts a tombstone;
     * link B, later in the SAME run, was already served by a memo prefetched
     * before that insert, and is not rejected. Pre-fix, every call ran a
     * fresh EXISTS, so this race had a negligible per-link window; this memo
     * widens it to the whole run's duration. It requires BOTH a concurrent
     * dismiss AND two hrefs canonicalising to one target in the same run —
     * narrow, not broad.
     *
     * Bounded by TTL (2026-08-18, discharging the original tripwire): a
     * dismissal lands in a DIFFERENT request/process, so in-memory
     * invalidation cannot reach a primed memo — instead the memo self-expires
     * after TOMBSTONE_MEMO_TTL_SECONDS and re-reads the table, narrowing the
     * staleness window to roughly the pre-memo per-link EXISTS while keeping
     * the batch savings (~1 query per TTL window instead of 1 per link).
     * Required before LinkInBioScanJob was wired onto LinkInBioImporter —
     * see docs/superpowers/plans/2026-08-18-linkinbio-unroll-migration.md.
     * Pinned by TombstoneMemoTtlTest.
     */
    private function isTombstoned(User $user, Projection $projection, RoutingContext $context): bool
    {
        $refs = [$projection->surfaceKey];
        if ($projection->identifier !== null) {
            $refs[] = $projection->surfaceKey.':'.$projection->identifier;
        }

        if ($context->importRunId === null) {
            return DB::table('routing.item_tombstones')
                ->where('user_id', $user->id)
                ->whereIn('source_ref', $refs)
                ->exists();
        }

        $key = $user->id.'|'.$context->importRunId;
        $expired = $this->tombstoneMemo !== null
            && (microtime(true) - $this->tombstoneMemo['at']) > self::TOMBSTONE_MEMO_TTL_SECONDS;

        if ($this->tombstoneMemo === null || $this->tombstoneMemo['key'] !== $key || $expired) {
            // No ->limit(): missing a tombstone here resurrects a link the
            // user explicitly refused — the exact C8 invariant this method's
            // caller protects. Row count is bounded by how many links this
            // ONE user has ever refused, not by the size of the batch being
            // imported, so pulling the full set is safe.
            $sourceRefs = DB::table('routing.item_tombstones')
                ->where('user_id', $user->id)
                ->pluck('source_ref');

            $this->tombstoneMemo = ['key' => $key, 'at' => microtime(true), 'refs' => array_flip($sourceRefs->all())];
        }

        foreach ($refs as $ref) {
            if (isset($this->tombstoneMemo['refs'][$ref])) {
                return true;
            }
        }

        return false;
    }
}
