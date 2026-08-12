<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The hand-add half of a pool (platforms-as-sources): POST a URL, get a
// MANUAL-source content item, pinned into the selection.
//
// The write goes through ProjectionWriter::writeManualItem() rather than
// bespoke SQL (slice 0b). The earlier hand-rolled version wrote the item and
// its facets but no identity keys and no anchor, so the next connector run
// for the same kind resolved the keyless source item as a singleton, minted a
// blank content.items row for it, and repointed the hand-added source item
// onto that blank — leaving the owner's item detached from its own source row
// and a duplicate in their library.
//
// Deliberately thin on enrichment: the headline defaults to the URL's host
// when the caller sends none. A hand-add is the owner typing a link they
// already know — the honest contract is "it appears, titled what you called
// it". The identity lane may fold it into a synced item, now or later.
class PoolItemCreateController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly PoolResolver $resolver,
        private readonly PoolSectionProvisioner $provisioner,
        private readonly ProjectionWriter $writer,
    ) {}

    /** POST /api/content/pools/{pool}/items  { url, title?, kind? } */
    public function store(Request $request, string $pool): JsonResponse
    {
        if (! PoolRegistry::isPool($pool)) {
            abort(404, 'Unknown pool.');
        }

        $kinds = PoolRegistry::kinds($pool);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'title' => ['nullable', 'string', 'max:300'],
            // The pool's own kinds only — "episode" can't land in Watch.
            'kind' => ['nullable', 'string', 'in:'.implode(',', $kinds)],
        ]);

        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $kind = $data['kind'] ?? $kinds[0];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = (string) (parse_url($data['url'], PHP_URL_HOST) ?: $data['url']);
        }

        // ONE coord per url, never a fresh uuid per request. Two manual coords
        // carrying the same url poison it for the whole resolution run —
        // Resolver::poisonedKeys() drops a value a single source contributes
        // twice, and a user has exactly one manual source — which would stop
        // the synced item unioning too. Canonicalised the same way
        // KeyClass::CanonicalUrl does it, so two urls that would union always
        // share a coord.
        $coord = 'manual:'.sha1(strtolower(trim($data['url'])));

        // No wrapping transaction: writeManualItem() manages its own, and
        // ProjectionWriter::replaceCollections() documents that no caller
        // holds one over the projection path.
        //
        // The returned id may be an item that already exists — a hand-typed
        // URL matching a synced one folds into it, which is the whole point
        // of routing hand-adds through the identity spine.
        $itemId = $this->writer->writeManualItem($user->id, $coord, [
            'kind' => $kind,
            'headline' => $title,
            'facets' => ['f_link' => ['url' => $data['url']]],
        ]);

        $section = $this->provisioner->ensure($site, $pool);

        // A hand-add is a pick by definition — pin it at the end. Conditional
        // because the fold-in above can hand back an item that is ALREADY
        // pinned, and site.section_items carries UNIQUE (section_id, item_id).
        $alreadyPinned = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->exists();

        if (! $alreadyPinned) {
            $highest = SectionItem::query()
                ->where('section_id', $section->id)
                ->where('state', SectionItem::STATE_PINNED)
                ->max('sort_key');
            $pin = new SectionItem;
            $pin->section_id = (string) $section->id;
            $pin->item_id = $itemId;
            $pin->state = SectionItem::STATE_PINNED;
            $pin->sort_key = $highest === null ? 1.0 : ((float) $highest) + 1.0;
            $pin->created_at = now();
            $pin->save();
        }

        // writeManualItem() already bumped the build state for the content
        // write; this covers the curation write above. Both are cheap
        // increments, and a missed bump is a stale public document.
        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        return $this->success($this->resolver->resolve($site, $pool), 201);
    }
}
