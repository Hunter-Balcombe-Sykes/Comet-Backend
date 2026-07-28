<?php

namespace App\Routing;

use App\Catalog\CatalogIntegrityCheck;
use App\Catalog\CompiledCatalog;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
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
        if ($context->user !== null && ! $context->isDirectRequest() && $this->isTombstoned($context->user, $projection)) {
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
            $why = $confidence < $auto
                ? 'below auto-apply threshold'
                : 'two rules matched too closely to decide automatically';

            return new Placement(Verdict::Choose, $surfaceKey, $projection->identifier, 'below_threshold', $why);
        }

        return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'below_threshold', 'kept as a link');
    }

    /** The sanctioned capability read — never a raw account_type branch. */
    private function capabilityDenial(User $user, string $routingClass): ?string
    {
        $capabilities = AccountCapabilities::for($user);

        return match ($routingClass) {
            'booking' => $capabilities->can_use_booking ? null : 'booking is not available for this account',
            'reservations' => $capabilities->can_use_reservations ? null : 'reservations are not available for this account',
            'ordering' => $capabilities->can_use_online_ordering ? null : 'online ordering is not available for this account',
            default => null,
        };
    }

    /**
     * A tombstone is keyed by what the user refused: either this exact source
     * item (surface:identifier) or the whole surface. `scope` widens the
     * refusal across sources at the ITEM level, which needs identity
     * resolution — that lookup joins in at P4; here, matching the ref itself
     * is the whole rule.
     */
    private function isTombstoned(User $user, Projection $projection): bool
    {
        $refs = [$projection->surfaceKey];
        if ($projection->identifier !== null) {
            $refs[] = $projection->surfaceKey.':'.$projection->identifier;
        }

        return DB::table('routing.item_tombstones')
            ->where('user_id', $user->id)
            ->whereIn('source_ref', $refs)
            ->exists();
    }
}
