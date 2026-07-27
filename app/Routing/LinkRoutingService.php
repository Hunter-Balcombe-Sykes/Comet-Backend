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
    ) {}

    /**
     * Decide without writing anything — powers the debounced paste preview.
     *
     * @return array<string, mixed>
     */
    public function preview(string $url, RoutingContext $context): array
    {
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
        $iri = $this->canonicalizer->canonicalize($url);
        $projection = $this->projector->project($iri);
        $placement = $this->policy->decide($projection, $context);

        $this->observer->record($iri, $projection, $placement, $context);
        $applied = $this->reconciler->reconcile($placement, $context, $iri);

        return $this->describe($iri, $projection, $placement) + [
            'intentId' => $applied['intent_id'],
            'connectionId' => $applied['connection_id'],
            // The reconciler can downgrade Place → Hold (conflict, cap), so
            // the verdict it returns is the authoritative one.
            'verdict' => $applied['verdict'],
            'blockReason' => $applied['block_reason'],
        ];
    }

    /** @return array<string, mixed> */
    private function describe(Iri $iri, Projection $projection, Placement $placement): array
    {
        $surface = $placement->surfaceKey !== null ? CompiledCatalog::surface($placement->surfaceKey) : null;

        return [
            'verdict' => $placement->verdict->value,
            'canonicalUrl' => $iri->canonical,
            'routedTo' => $surface === null ? null : [
                'surfaceKey' => $placement->surfaceKey,
                'brandKey' => $surface['brand_key'],
                'displayName' => $surface['display_name'],
                'routingClass' => $surface['routing_class'],
                'identifier' => $placement->identifier,
            ],
            'confidence' => $projection->matched() ? $projection->confidence : null,
            'blockReason' => $placement->blockReason,
            'explanation' => $placement->explanation,
            'conflictingConnectionId' => $placement->conflictingConnectionId,
        ];
    }
}
