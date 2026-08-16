<?php

namespace App\Services\Migration;

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\Shop\ShopConnections;

/**
 * Convergence Phase 6: move the 41 live pseudo-platform connections to the homes
 * the disposition table gives them, then retire the rows.
 *
 * Production code, not a script: idempotent and re-runnable (parent invariant
 * #4), because a migration that can only be run once cannot be rehearsed.
 *
 * Every row lands in exactly one of three places:
 *
 *   REPOINT  the row keeps its identity and moves to its real brand surface.
 *            Only `partna.order_link` rows whose host names an ordering brand,
 *            and only when that brand's single store slot is free (owner ruling
 *            1). resource_id is untouched — it is `order-<hash>`, and
 *            SiteActionsService emits `ordering:<resource_id>` action ids that
 *            owners store display preferences against.
 *
 *   POOL     the row becomes a `custom_links` item carrying its provider label,
 *            then is retired (owner ruling 2A). Every no-brand-home case: an
 *            ordering link whose brand does not exist, a second store for a
 *            brand already occupied, and both reservation rows (rulings 2+3 —
 *            errols' OpenTable link carries no `rid` so the widget would render
 *            broken, and SevenRooms loses ollies' single slot to OpenTable).
 *
 *   RETIRE   the row is retired with nothing written, because its content is
 *            already where it belongs. `partna.custom_link` (Phase 3 carried
 *            all 23 onto the pool), the `partna.storefront` markers once their
 *            brands are repointed, and an empty marker with no brands at all.
 *
 * The shop half is not a row move: a marker anchors MANY stores, so each of its
 * `site.shop_brands` children is repointed onto its own new anchor first (owner
 * ruling 4) and only then is the marker retired.
 */
class PseudoPlatformRetirer
{
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkPoolWriter $pool,
        private readonly ShopConnections $shop,
    ) {}

    /**
     * @return array<string, int> counters, keyed by outcome
     */
    public function run(bool $dryRun = false): array
    {
        $this->counts = [
            'repointed' => 0, 'pooled' => 0, 'retired' => 0,
            'shop_brands_repointed' => 0, 'skipped_no_url' => 0,
            'skipped_no_site' => 0, 'already_done' => 0,
        ];

        foreach (IntegrationConnection::RETIRED_SURFACES as $surface) {
            $rows = IntegrationConnection::query()
                ->where('surface_key', $surface)
                ->orderBy('created_at')
                ->get();

            foreach ($rows as $row) {
                $this->handle($row, $dryRun);
            }
        }

        return $this->counts;
    }

    private function handle(IntegrationConnection $row, bool $dryRun): void
    {
        $user = User::find($row->user_id);
        if ($user === null) {
            // Orphaned row — nothing to migrate FOR. Retiring it is still
            // correct: the guard refuses new ones and this cannot be reached.
            $this->retire($row, $dryRun);

            return;
        }

        match ((string) $row->surface_key) {
            'partna.storefront' => $this->handleStorefront($user, $row, $dryRun),
            'partna.order_link' => $this->handleOrdering($user, $row, $dryRun),
            'partna.reserve_link', 'partna.booking_link', 'partna.manual_event' => $this->handleToPool($user, $row, $dryRun),
            // Phase 3 already carried all 23 onto the pool and the write path
            // moved with it, so there is nothing to write — only to retire.
            default => $this->retire($row, $dryRun),
        };
    }

    /**
     * Ordering: repoint to the brand surface when the host names one AND that
     * brand's single store slot is free; otherwise pool it.
     *
     * "Free" is judged against LIVE rows only, and against the SAME url check
     * LinkRouter::seedOnlineOrdering uses — a row already sitting on the brand
     * with this exact url is this row's own prior migration, not a rival store.
     */
    private function handleOrdering(User $user, IntegrationConnection $row, bool $dryRun): void
    {
        $url = $this->urlOf($row);
        if ($url === null) {
            $this->counts['skipped_no_url']++;

            return;
        }

        $classified = $this->harvester->classify($url);
        $surface = ($classified !== null && $classified['category'] === 'online-ordering')
            ? (LegacyPlatformMap::surfaceFor($classified['platform']) ?? $classified['platform'])
            : null;

        if ($surface === null) {
            $this->handleToPool($user, $row, $dryRun);

            return;
        }

        $occupied = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surface)
            ->where('id', '!=', $row->id)
            ->get()
            ->contains(fn (IntegrationConnection $other) => ! $this->sameUrl(
                (string) (CardPayload::fromArray($other->payload)->url() ?? ''), $url,
            ));

        if ($occupied) {
            // Owner ruling 1: one store per ordering brand. The second one is a
            // link, not a second connection.
            $this->handleToPool($user, $row, $dryRun);

            return;
        }

        if (! $dryRun) {
            // surface_key only. resource_id, payload, timestamps and the row's
            // own id all stay — this is the same connection, correctly typed.
            $row->surface_key = $surface;
            $row->routing_class = LegacyPlatformMap::routingClassFor($surface);
            $row->save();
        }

        $this->counts['repointed']++;
    }

    /** A no-brand-home row: preserve it as a links-pool item, then retire it. */
    private function handleToPool(User $user, IntegrationConnection $row, bool $dryRun): void
    {
        $url = $this->urlOf($row);
        if ($url === null) {
            $this->counts['skipped_no_url']++;

            return;
        }

        // A pool item needs a section, which hangs off the site. Retiring a row
        // whose content could not be preserved would DESTROY it, so this stops
        // instead and reports — a siteless owner is a case to look at, not to
        // silently drop.
        if ($user->site === null) {
            $this->counts['skipped_no_site']++;

            return;
        }

        $card = CardPayload::fromArray($row->payload);

        if (! $dryRun) {
            // The provider label is preserved as the headline (owner ruling 2A).
            // add() is idempotent on the url-derived coord, so a re-run updates
            // the one item rather than forking a second.
            $this->pool->add($user, $url, $card->name() ?? $card->provider(), null);
        }

        $this->counts['pooled']++;
        $this->retire($row, $dryRun);
    }

    /**
     * A storefront marker: repoint each of its stores onto its own anchor
     * (owner ruling 4), then retire the marker.
     *
     * An empty marker — a store slot holding nothing — is retired outright.
     * `spinosaurus…` on dev is exactly that: 0 brands, 0 products.
     */
    private function handleStorefront(User $user, IntegrationConnection $row, bool $dryRun): void
    {
        $brands = ShopBrand::where('connection_id', $row->id)->get();

        foreach ($brands as $brand) {
            if (! $dryRun) {
                $anchor = $brand->is_individual
                    ? $this->shop->individualAnchor($user)
                    : $this->shop->anchor($user, (string) $brand->provider, (string) $brand->brand_id);

                $brand->connection_id = $anchor->id;
                $brand->save();
            }

            $this->counts['shop_brands_repointed']++;
        }

        $this->retire($row, $dryRun);
    }

    /** Soft-delete — the observer purges the sitepage cache and stands the ingest source down. */
    private function retire(IntegrationConnection $row, bool $dryRun): void
    {
        if ($row->trashed()) {
            $this->counts['already_done']++;

            return;
        }

        if (! $dryRun) {
            $row->setRelation('user', $row->user()->first());
            $row->delete();
        }

        $this->counts['retired']++;
    }

    private function urlOf(IntegrationConnection $row): ?string
    {
        $url = trim((string) (CardPayload::fromArray($row->payload)->url() ?? ''));

        return $url === '' ? null : $url;
    }

    /** Byte-identical to LinkRouter::sameUrl's intent — trailing slash and case are not a different store. */
    private function sameUrl(string $a, string $b): bool
    {
        return rtrim(strtolower(trim($a)), '/') === rtrim(strtolower(trim($b)), '/');
    }
}
