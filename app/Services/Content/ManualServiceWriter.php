<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Site\Pools\PoolSectionProvisioner;

/**
 * Slice 3a §3.1/§3.5: the ONE place that turns a legacy-shaped service (a
 * dashboard request payload — and, until the services cutover retired it, a
 * `site.services` row) into a `content.*` projection plus pool curation state.
 * It was shared by `ServiceBackfiller` (the one-off migration, now deleted with
 * its table) and `UserServiceController` (the live write cutover) so the two
 * write paths could not diverge — two independent copies of this mapping is exactly the
 * class of bug slice 2 shipped: `removeEvent()` wrote a lane the pool never
 * read, so every hide silently failed until it was caught.
 *
 * Everything except the mapping below now lives on ManualPoolWriter (slice 7),
 * so this class is the services-shaped projection and nothing else.
 */
class ManualServiceWriter extends ManualPoolWriter
{
    public function __construct(
        ProjectionWriter $writer,
        PoolSectionProvisioner $sections,
        ContentItemSlugAllocator $slugs,
    ) {
        parent::__construct($writer, $sections, $slugs, 'services');
    }

    /**
     * The legacy-shape → content.* projection mapping (spec §3.1's table).
     * Accepts anything with the legacy service columns as public properties
     * (a `site.services` row from the backfill, or a plain object assembled
     * from a dashboard request) — same mapping either way.
     *
     * @param  list<string>  $forceFacets  B1: field names ('description',
     *                                     'duration_minutes') the CALLER is
     *                                     actively setting THIS request —
     *                                     UserServiceController::update()
     *                                     passes the PATCH payload's own
     *                                     keys. A field in this list writes
     *                                     its facet even when the resolved
     *                                     value is null, so
     *                                     the singleton-facet write path (which only
     *                                     touches columns present in its
     *                                     input) actually clears a
     *                                     previously-set value instead of
     *                                     leaving it behind. A field NOT in
     *                                     this list with a null value omits
     *                                     the facet entirely — required so a
     *                                     brand-new create/backfill (nothing
     *                                     to clear, nothing ever forced)
     *                                     doesn't grow a facet row it never
     *                                     had. Empty by default — a create
     *                                     never needs it, since a new item has
     *                                     nothing to clear either way.
     * @return array<string, mixed>
     */
    public function projectionFor(object $service, array $forceFacets = []): array
    {
        $title = trim((string) ($service->title ?? ''));
        $description = trim((string) ($service->description ?? ''));

        // 'headline' stays filtered: title is `required` on every write path
        // (never legitimately blank), so an empty title omits the key rather
        // than risk clobbering a real headline with '' turned to null.
        $projection = [
            'kind' => 'service',
            'headline' => $title,
            'facets' => ['f_text' => array_filter([
                'headline' => $title !== '' ? $title : null,
            ], static fn ($v) => $v !== null)],
        ];

        if ($description !== '' || in_array('description', $forceFacets, true)) {
            $projection['facets']['f_text']['body'] = $description !== '' ? $description : null;
        }

        if ($service->duration_minutes !== null || in_array('duration_minutes', $forceFacets, true)) {
            $projection['facets']['f_duration'] = [
                'seconds' => $service->duration_minutes !== null ? ((int) $service->duration_minutes) * 60 : null,
            ];
        }

        if ($service->price_cents !== null) {
            $cents = (int) $service->price_cents;
            $projection['offers'] = [[
                // §1.2: a HAND-ENTERED zero means free. Scraped zeros are 3b's
                // problem and must not be routed through this mapper.
                'qualifier' => $cents === 0 ? 'free' : 'exact',
                'amount_minor' => $cents === 0 ? null : $cents,
                'currency' => $cents === 0 ? null : (string) $service->currency_code,
            ]];
        }

        return $projection;
    }
}
