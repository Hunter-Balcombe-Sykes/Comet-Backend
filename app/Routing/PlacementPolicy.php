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
 *   5. Gate 3 — does the rule that matched name an ACCOUNT, or only the
 *      brand? (LinkValidity; replaced the confidence thresholds, 2026-09-03)
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
        // Pre-account builds have no user and therefore no capability set.
        if ($context->user !== null) {
            $denied = $this->capabilityDenial($context->user, $routingClass);
            if ($denied !== null) {
                return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'gate', $denied);
            }

            // ── Gate 1a: whose page was this link found on? ──────────────────
            // A partna's previous_website is their WORKPLACE's site, so the
            // profiles on it belong to the venue and its staff, not to them.
            // Reject rather than Note: a Note keeps it as a public link card
            // on their page, which is the same wrong claim one layer down.
            $foreign = RoutingCapabilityGate::foreignIdentityDenial($context->user, $routingClass, $context->origin);
            if ($foreign !== null) {
                return Placement::reject($foreign, $surfaceKey);
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

        // ── Gate 3: does this link identify an ACCOUNT, or just a brand? ────
        // The owner's rule, at the ONE place every lane passes through
        // (2026-09-03): "never let it be saved if it fails as a connectable +
        // active platform". A URL that matched nothing but the brand's
        // registrable domain names no account — the identifier the projector
        // hands back is the whole URL — so it cannot support a CTA, a refresh,
        // or a name on a card. Note is the right answer rather than a refusal:
        // Note's standing promise is "kept as a link, never dropped", so the
        // person still gets their link, minus the false claim that we know
        // whose account it is.
        //
        // Scoped to surfaces that have a shape ON FILE. The nine connectable
        // surfaces with no specific detector anywhere are ones we have no
        // standing to judge; they stay on the old path and are checked at
        // accept time by the L2 lane instead. That asymmetry is the point: we
        // refuse a claim only where we can say what a real one looks like.
        //
        // This is what makes paste and harvest agree with the manual connect
        // lane, which asks the same question in BrandLinkConnect::shapeRefusal.
        if (LinkValidity::applies($surface)
            && LinkValidity::l1($projection) === LinkValidity::WEAK
            && LinkValidity::hasShape($surfaceKey)) {
            // identifier deliberately null: a host-only match's "identifier" IS
            // the URL, and passing it on is how the nameless-card row got
            // written in the first place.
            return new Placement(Verdict::Note, $surfaceKey, null, 'invalid_identifier', 'matched the brand, not an account page');
        }

        // ── The decision ────────────────────────────────────────────────────
        //
        // What is left after the confidence system was deleted (2026-09-03):
        // Gate 3 above has already answered the only question the thresholds
        // were a proxy for. A link that reaches this point matched a rule that
        // constrains more than the brand's domain, or matched a surface we hold
        // no shape for. Either way there is nothing further to score.
        //
        //   contested  a rule for a DIFFERENT surface matched too, so which
        //              brand this is remains open. Never applied unasked;
        //              still worth asking about.
        //   band       'auto' when the rule CAPTURED an identifier AND nothing
        //              else claims the link — we can name the account, so the
        //              row arrives pre-ticked (owner, 2026-09-03). 'suggest'
        //              when the rule matched a shape but named nobody, or when
        //              two brands both claim it: still a real suggestion, just
        //              not one we should answer on the person's behalf.
        //
        // Computed ONCE, above both arms. It was originally derived from the
        // identifier alone and folded `contested` in only at the final return,
        // which meant a contested match on a SIGN-UP build — the one lane where
        // every row is pre-ticked by default — arrived ticked anyway. Pre-ticking
        // a link two brands both claim is precisely the answer we have no
        // standing to give.
        $band = $projection->identifier !== null && ! $projection->contested ? 'auto' : 'suggest';

        // Sign-up builds connect nothing by themselves (A.2): every match is a
        // Choose the setup dialog renders. Rejecting a wrong find there is one
        // untick, not a wrong CTA on a live page.
        if ($context->isSignupBuild()) {
            return new Placement(Verdict::Choose, $surfaceKey, $projection->identifier, null, 'held for setup review', band: $band);
        }

        // NOTHING A HARVESTER FOUND EVER AUTO-CONNECTS (owner, 2026-09-03).
        // Place is minted ONLY for a link the user asked for in this very
        // request — a submitted paste, or an accept pressed in the suggestions
        // inbox — and only when the link names an account and nothing else
        // claims it.
        //
        // The 2026-08-18 "harvest maximisation" arm that used to sit below this
        // is DELETED and is not coming back: on any indirect origin the suggest
        // band auto-applied, which meant `suggest` was never a
        // show-a-suggestion threshold at all — it was the auto-connect
        // threshold for every post-claim harvest lane. Loosening suggestions
        // would have loosened auto-connect, the opposite of the intent. Its
        // stated reason (avoid friction on a link the user demonstrably
        // published) is served by PRE-TICKING instead: the link still arrives
        // one Continue away, but it passes the validity gate on the way.
        //
        // Place also still arrives at SourceReconciler:134-135, where a Choose
        // matching an existing ConnectionIdentity is upgraded — folding a
        // variant URL into a row the person already holds adds no account and
        // asks no question, so it is not an auto-connect. Sign-up-given
        // identities (Google Business place_id, Instagram handle) never reach
        // this method at all: their identity kind is not url.
        if ($context->isConfirmedByUser() && $projection->identifier !== null && ! $projection->contested) {
            return new Placement(Verdict::Place, $surfaceKey, $projection->identifier, band: 'auto');
        }

        return new Placement(
            Verdict::Choose,
            $surfaceKey,
            $projection->identifier,
            null,
            $projection->contested
                ? 'two brands both claim this link'
                : 'confirm this is yours',
            band: $band,
        );
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
    /**
     * The tombstone question, askable on its own (T9b critic fix,
     * 2026-08-20): decide() skips the check for DIRECT requests — a person
     * re-pasting a link they removed means "bring it back" — but a caller
     * suggesting something DERIVED from what was pasted (the channel behind
     * a video) must still honour the refusal, whatever the origin was. One
     * implementation, exposed rather than copied.
     */
    public function tombstoned(User $user, string $surfaceKey, ?string $identifier, RoutingContext $context): bool
    {
        return $this->isTombstoned(
            $user,
            new Projection(surfaceKey: $surfaceKey, detectorId: null, captures: [], identifier: $identifier, reason: null),
            $context,
        );
    }

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
