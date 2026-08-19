<?php

namespace App\Services\Platforms;

use App\Models\Core\User\User;
use App\Routing\ConnectionIdentity;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkObserver;
use App\Routing\LinkProjector;
use App\Routing\Placement;
use App\Routing\PlacementPolicy;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\Log;

// T9b (owner, 2026-08-20): the store pattern, for media — a product paste
// auto-connects its STORE (ConnectStoreFromProductJob); a media item now
// SUGGESTS its parent account (the channel behind the video, the artist
// behind the release) in the routing suggestions inbox.
//
// SUGGEST-ONLY by design, on every lane including paste (owner default; the
// auto-connect upgrade for the paste lane is flagged as an owner decision in
// the run report): an item is weaker evidence than a direct account link —
// bios are full of other people's content — so a would-be Place from the
// catalog is DOWNGRADED to Choose here, never applied.
//
// Tombstone-safe by REUSE, not reimplementation: the parent URL runs through
// the real projector + PlacementPolicy first, so a platform the owner
// disconnected answers Reject('tombstoned') exactly as it would for any
// scanned link, and this class stays out of the tombstone business.
class MediaParentSuggester
{
    /** Origins the intent ledger accepts — anything else skips quietly. */
    private const ORIGINS = ['paste', 'website_import', 'link_in_bio', 'bio_harvest'];

    public function __construct(
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkProjector $projector,
        private readonly PlacementPolicy $policy,
        private readonly ConnectionIdentity $identity,
        private readonly LinkObserver $observer,
        private readonly SourceReconciler $reconciler,
    ) {}

    /**
     * File the parent-account suggestion for an item that just landed.
     * Best-effort: never throws into the item write; returns the intent id
     * or null (unknown parent, already connected, tombstoned, cap…).
     */
    public function suggest(User $user, ?string $authorUrl, ?string $origin): ?string
    {
        if ($authorUrl === null || $authorUrl === '' || ! in_array($origin, self::ORIGINS, true)) {
            return null;
        }

        try {
            $context = RoutingContext::forUser($user, (string) $origin);
            $iri = $this->canonicalizer->canonicalize($authorUrl);
            $projection = $this->projector->project($iri);
            if (! $projection->matched() || $projection->surfaceKey === null) {
                return null;
            }

            // The real policy first — tombstones, gates, thresholds all apply
            // exactly as they would to the URL itself.
            $placement = $this->policy->decide($projection, $context);
            if (! in_array($placement->verdict, [Verdict::Place, Verdict::Choose], true) || $placement->surfaceKey === null) {
                return null;
            }

            // Already connected (aliases included): nothing to suggest.
            $identifier = $placement->identifier ?? $iri->canonical ?? '';
            if ($this->identity->matchExisting($user, $placement->surfaceKey, $identifier) !== null) {
                return null;
            }

            // The downgrade: an item's parent is a QUESTION, never an
            // auto-connect. Choose writes a 'proposed' intent — the inbox row.
            $placement = new Placement(Verdict::Choose, $placement->surfaceKey, $placement->identifier, $placement->blockReason);

            $this->observer->record($iri, $projection, $placement, $context);

            return $this->reconciler->reconcile($placement, $context, $iri)['intent_id'];
        } catch (\Throwable $e) {
            report($e);
            Log::warning('media_parent_suggester.failed', [
                'user_id' => (string) $user->id,
                'origin' => $origin,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
