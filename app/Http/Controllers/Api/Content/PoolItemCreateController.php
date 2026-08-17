<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Content\EnrichPoolLinkJob;
use App\Models\Content\ManualOverride;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Services\Shop\ProductPageAdder;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        private readonly ProductPageAdder $products,
    ) {}

    /** POST /api/content/pools/{pool}/items  { url, title?, description?, favicon?, logo?, kind? } */
    public function store(Request $request, string $pool): JsonResponse
    {
        if (! PoolRegistry::isPool($pool)) {
            abort(404, 'Unknown pool.');
        }

        // Slice 6 §4.4: hand-authoring an item of kind `review` is fabricating
        // a testimonial attributed to a customer. Declared once in PoolRegistry
        // so this endpoint and the curation write path read one source of
        // truth. A capability rule about the pool, not authorization on a
        // resource — hence 422, not a policy.
        if (! PoolRegistry::allowsManualAdd($pool)) {
            abort(422, 'Reviews come from your connected platforms and cannot be added by hand.');
        }

        $kinds = PoolRegistry::kinds($pool);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'title' => ['nullable', 'string', 'max:300'],
            // The checked card from /content/links/preview (2026-08-17): the
            // owner saw these and may have changed the words. Images are the
            // page's own URLs, stored as borrowed media the way every
            // connector thumbnail is.
            'description' => ['nullable', 'string', 'max:2000'],
            'favicon' => ['nullable', 'string', 'max:2048', 'url:https'],
            'logo' => ['nullable', 'string', 'max:2048', 'url:https'],
            // The pool's own kinds only — "episode" can't land in Watch.
            'kind' => ['nullable', 'string', 'in:'.implode(',', $kinds)],
        ]);

        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        // STORE-FIRST for a product (owner, 2026-08-17): read the page as a
        // PRODUCT (JSON-LD/OG — title, price, variants, gallery), write it
        // through the shop lane's own projection, and connect its store off-
        // thread if it's one we can read. Falls through to the plain card
        // path only when the page carries no product markup at all — then it
        // is a link with a picture, which is what the card path stores.
        if ($pool === 'shop' && ($data['kind'] ?? 'product') === 'product') {
            $added = $this->products->add($user, $data['url']);
            if ($added['outcome'] === ProductPageAdder::OUTCOME_STORE_PAGE) {
                abort(422, "That looks like a store's homepage, not a product page. Connect it as a platform to bring in its products, or paste a specific product's page.");
            }
            if ($added['outcome'] === ProductPageAdder::OUTCOME_PRODUCT && $added['itemId'] !== null) {
                $this->applyCheckedWords($user->id, $added['itemId'], $data);
                $this->pin($site, $pool, $added['itemId']);

                return $this->success($this->resolver->resolve($site, $pool), 201);
            }
            // no_product / unreachable → the card path below.
        }

        // ONE coord per url, never a fresh uuid per request. Two manual coords
        // carrying the same url poison it for the whole resolution run —
        // Resolver::poisonedKeys() drops a value a single source contributes
        // twice, and a user has exactly one manual source — which would stop
        // the synced item unioning too. Canonicalised the same way
        // KeyClass::CanonicalUrl does it, so two urls that would union always
        // share a coord.
        $coord = 'manual:'.sha1(strtolower(trim($data['url'])));

        // What this url already resolved to, if the owner has added it before.
        // Three of the decisions below turn on it, and a deterministic coord
        // is what makes "before" reachable at all.
        $existing = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $user->id)
            ->where('cs.kind', 'manual')
            ->where('si.coord', $coord)
            ->first(['si.kind', 'si.item_id']);

        // A kind change on an existing coord is REFUSED, not applied. Folding
        // kind into the coord would be the other way to keep them consistent,
        // but that mints a second coord for one url and poisons it. Letting
        // the change through instead desynchronises source_items.kind from the
        // anchored items.kind, and the item then has no live source item of
        // its own kind — no future resolveItems($user, $kind) pass ever touches
        // it again.
        $kind = $data['kind'] ?? $kinds[0];
        if ($existing !== null && (string) $existing->kind !== $kind) {
            if (isset($data['kind'])) {
                abort(422, "This link is already in your library as a '{$existing->kind}'. Remove it first to add it as a '{$kind}'.");
            }
            // No explicit kind was asked for, so the pool default collided with
            // what is already there. Keep what is already there.
            $kind = (string) $existing->kind;
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            // Only fall back to the host for a genuinely NEW item. The manual
            // source sits at priority 200, so on a re-add this would otherwise
            // overwrite the owner's real title with "vimeo.com" and win against
            // every connector headline product-wide.
            $title = $this->storedHeadline($user->id, $existing?->item_id)
                ?? (string) (parse_url($data['url'], PHP_URL_HOST) ?: $data['url']);
        }

        // No wrapping transaction: writeManualItem() manages its own, and
        // ProjectionWriter::replaceCollections() documents that no caller
        // holds one over the projection path.
        //
        // The returned id may be an item that already exists — a hand-typed
        // URL matching a synced one folds into it, which is the whole point
        // of routing hand-adds through the identity spine.
        $projection = [
            'kind' => $kind,
            'headline' => $title,
            'facets' => ['f_link' => ['url' => $data['url']]],
        ];
        $description = trim((string) ($data['description'] ?? ''));
        if ($description !== '') {
            $projection['facets']['f_text'] = ['body' => $description];
        }
        $media = [];
        $logo = trim((string) ($data['logo'] ?? ''));
        $favicon = trim((string) ($data['favicon'] ?? ''));
        if ($logo !== '') {
            $media[] = ['role' => 'cover', 'url' => $logo];
        }
        if ($favicon !== '') {
            $media[] = ['role' => 'logo', 'url' => $favicon];
        }
        if ($media !== []) {
            $projection['media'] = $media;
        }

        $itemId = $this->writer->writeManualItem($user->id, $coord, $projection);

        // A link added without a checked card (an older client, or a paste
        // that skipped the preview) still gets the page read off-thread —
        // title off the host fallback, body if empty, images always. The
        // two-step dashboard flow sends the card itself, so nothing here
        // races what the owner just checked.
        if ($kind === 'link' && $media === [] && $description === '') {
            EnrichPoolLinkJob::dispatch((string) $user->id, $data['url'])->afterCommit();
        }

        // A hand-add is an explicit "I want this", so it un-deletes. The
        // deterministic coord means a re-add resolves to the SAME item, and
        // upsertSourceItem() clears only source_items.removed_at — the
        // user-level delete lives on items.removed_at, which PoolResolver
        // filters on. Without this the owner re-adds a link they previously
        // removed, gets a 201, and nothing appears, with no route back.
        //
        // Deliberately here and not in writeManualItem(): a BACKFILL running
        // through the same lane must not resurrect what someone deleted. Only
        // a person typing the link again means "bring it back".
        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', $user->id)
            ->whereNotNull('removed_at')
            ->update(['removed_at' => null, 'updated_at' => now()]);

        $this->pin($site, $pool, $itemId);

        return $this->success($this->resolver->resolve($site, $pool), 201);
    }

    /**
     * A hand-add is a pick by definition — pin it at the end. Find-or-new and
     * FORCE the state, mirroring PoolController::select(): the fold-in can
     * hand back an item that already has a curation row, and
     * site.section_items carries UNIQUE (section_id, item_id) across BOTH
     * states. Testing only for existence would skip an `excluded` row and
     * leave the item excluded — a hand-add that silently does nothing.
     */
    private function pin(Site $site, string $pool, string $itemId): void
    {
        $section = $this->provisioner->ensure($site, $pool);

        // A hand-add is a pick by definition — pin it at the end. Find-or-new
        // and FORCE the state, mirroring PoolController::select(): the fold-in
        // above can hand back an item that already has a curation row, and
        // site.section_items carries UNIQUE (section_id, item_id) across BOTH
        // states. Testing only for existence would skip an `excluded` row and
        // leave the item excluded — a hand-add that silently does nothing.
        $pin = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $pin->section_id = (string) $section->id;
        $pin->item_id = $itemId;
        $pin->state = SectionItem::STATE_PINNED;
        $pin->sort_key = $pin->sort_key ?? $this->nextSortKey((string) $section->id);
        if (! $pin->exists) {
            $pin->created_at = now();
        }
        $pin->save();

        // writeManualItem() already bumped lane 1 (build state only) for the
        // content write via ProjectionWriter::bumpSite() — this bust() covers
        // the curation write above, all three lanes. Correction (#PGR-6): a
        // hand-add therefore bumps content_revision TWICE per request (once
        // there, once here) while lanes 2+3 fire once — PoolCacheLanesTest
        // pins that exact +2 delta, so don't "simplify" this to a single
        // bust() call expecting +1.
        SiteCacheLanes::bust([(string) $site->id]);
    }

    /**
     * The owner's checked title/description from the two-step Add (they saw
     * the page's words and may have changed them) land as OVERRIDES on the
     * product the page became — the store's own values stay underneath, so a
     * later sync doesn't clobber what was typed and a reset brings them back.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyCheckedWords(string $userId, string $itemId, array $data): void
    {
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $current = DB::connection('pgsql')->table('content.items as i')
            ->leftJoin('content.f_text as ft', 'ft.item_id', '=', 'i.id')
            ->where('i.id', $itemId)
            ->where('i.user_id', $userId)
            ->first(['i.headline_cache', 'ft.body']);
        if ($current === null) {
            return;
        }
        if ($title !== '' && $title !== (string) $current->headline_cache) {
            $this->writeOverride($itemId, 'headline', $title);
        }
        if ($description !== '' && $description !== trim((string) ($current->body ?? ''))) {
            $this->writeOverride($itemId, 'body', $description);
        }
    }

    /** One f_text override row — the same write ManualOverrideController::upsert makes. */
    private function writeOverride(string $itemId, string $column, string $value): void
    {
        $override = ManualOverride::query()
            ->where('item_id', $itemId)
            ->where('facet', 'f_text')
            ->where('column_name', $column)
            ->first() ?? new ManualOverride;
        $override->item_id = $itemId;
        $override->facet = 'f_text';
        $override->column_name = $column;
        $override->value = $value;
        $override->save();
    }

    /** The headline this item already carries, so a title-less re-add does not clobber it. */
    private function storedHeadline(string $userId, ?string $itemId): ?string
    {
        if ($itemId === null) {
            return null;
        }

        $headline = DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->value('headline_cache');

        return is_string($headline) && $headline !== '' ? $headline : null;
    }

    /** Next free pin position, matching PoolController::select()'s ordering. */
    private function nextSortKey(string $sectionId): float
    {
        $highest = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');

        return $highest === null ? 1.0 : ((float) $highest) + 1.0;
    }
}
