<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;

/**
 * observe → project → place → reconcile, in one place. The named successor to
 * LinkRouter (deleted at P8). Two entry points that share every stage except
 * the last: preview() decides and explains without writing; route() also
 * reconciles.
 */
class LinkRoutingService
{
    public function __construct(
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkProjector $projector,
        private readonly PlacementPolicy $policy,
        private readonly SourceReconciler $reconciler,
        private readonly LinkObserver $observer,
        private readonly ShortLinkExpander $expander,
    ) {}

    /**
     * Decide without writing anything — powers the debounced paste preview.
     *
     * @return array<string, mixed>
     */
    public function preview(string $url, RoutingContext $context): array
    {
        // FI-3: a short link's identity lives behind its redirect, so expand
        // BEFORE canonicalize — the preview then describes the real
        // destination, and the expansion is cached for the route() that
        // follows the paste.
        $url = $this->expander->expandIfShort($url);

        $iri = $this->canonicalizer->canonicalize($url);
        $projection = $this->projector->project($iri);
        $placement = $this->policy->decide($projection, $context);

        return $this->describe($iri, $projection, $placement);
    }

    /**
     * Decide, record, and reconcile. Returns the preview shape plus what was
     * actually created.
     *
     * @return array<string, mixed>
     */
    public function route(string $url, RoutingContext $context): array
    {
        // FI-3 — see preview(). Cached, so the preview→route pair fetches once.
        $url = $this->expander->expandIfShort($url);

        $iri = $this->canonicalizer->canonicalize($url);
        $projection = $this->projector->project($iri);
        $placement = $this->policy->decide($projection, $context);

        $this->observer->record($iri, $projection, $placement, $context);
        $applied = $this->reconciler->reconcile($placement, $context, $iri);

        // The reconciler can downgrade Place → Hold (conflict, cap), so the
        // verdict it returns is the authoritative one. array_replace, not `+`:
        // `+` keeps the LEFT side on key collision, which silently discarded
        // these overrides.
        return array_replace($this->describe($iri, $projection, $placement), [
            'intentId' => $applied['intent_id'],
            'connectionId' => $applied['connection_id'],
            'verdict' => $applied['verdict'],
            'blockReason' => $applied['verdict'] === Verdict::Reject->value ? $applied['block_reason'] : null,
            // The placement's own reason, verdict-agnostic — distinct from
            // blockReason, which the dashboard reads as "cannot add" and is
            // therefore nulled for Notes. Importers key on this to tell an
            // unknown-domain Note (probe it) from an unservable/gated one
            // (card it). route()-only: preview()'s wire is unchanged.
            'reason' => $placement->blockReason,
        ]);
    }

    /** @return array<string, mixed> */
    private function describe(Iri $iri, Projection $projection, Placement $placement): array
    {
        $surface = $placement->surfaceKey !== null ? CompiledCatalog::surface($placement->surfaceKey) : null;
        $isNote = $placement->verdict === Verdict::Note;

        return [
            'verdict' => $placement->verdict->value,
            'canonicalUrl' => $iri->canonical,
            // A Note is not routed anywhere — it stays a plain link item, so
            // naming a surface here would read as a connection that isn't.
            'routedTo' => ($surface === null || $isNote) ? null : [
                'surfaceKey' => $placement->surfaceKey,
                'brandKey' => $surface['brand_key'],
                'displayName' => $surface['display_name'],
                'routingClass' => $surface['routing_class'],
                'identifier' => $placement->identifier,
            ],
            // ONLY a Reject blocks the add. The dashboard disables submit
            // whenever blockReason is set, so anything non-null here must mean
            // "cannot add" — a Note is kept as a link (Verdict::Note: never
            // dropped) and Choose/Hold go to the review inbox.
            'blockReason' => $placement->verdict === Verdict::Reject ? $placement->blockReason : null,
            'explanation' => $isNote
                ? (self::isStorefrontCandidate($projection, $placement)
                    ? "We'll keep this as a link on your site — and if it turns out to be your online store, we'll offer to add it as one."
                    // PlacementPolicy computes a SPECIFIC reason for most Notes
                    // (which capability gated it, which brand retired, why the
                    // match was too weak to name an account) — prefer it over
                    // the generic line so a gated paste preview says WHY
                    // ("booking is not available for this account") instead of
                    // a uniform "we'll keep this as a link" that reads the same
                    // for every reason. Found by the 2026-09-04 overnight
                    // suggestion-pipeline sweep: the specific text was computed
                    // and then unconditionally discarded here.
                    : ($placement->explanation ?? "We'll keep this as a link on your site."))
                : $placement->explanation,
            'conflictingConnectionId' => $placement->conflictingConnectionId,
            // Owner ask (2026-08-18): a pasted Shopify / WooCommerce / other
            // own-domain storefront used to land as a bare link with no way
            // in. When the catalog places nothing, the paste path runs the
            // storefront probe (queue-only, budgeted) and a hit lands in the
            // suggestions inbox as "Is this your store?" — never auto-applied
            // from a paste. `probe` tells the dashboard so the preview can
            // say so.
            'probe' => self::isStorefrontCandidate($projection, $placement) ? 'store' : null,
        ];
    }

    /**
     * A URL the catalog could not place at all — no surface, no detector hit
     * — is worth a storefront probe: merchants' own domains (beardbrand.com,
     * a WooCommerce shop) look like nothing to the pure projector.
     */
    public static function isStorefrontCandidate(Projection $projection, Placement $placement): bool
    {
        return $placement->verdict === Verdict::Note
            && $placement->surfaceKey === null
            && ! $projection->matched();
    }
}
