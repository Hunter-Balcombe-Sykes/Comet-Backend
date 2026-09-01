<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Facebook page events via the ScrapeCreators vendor lane (Item 11a,
 * 2026-09-01) — an EXISTING facebook connection's SECOND source, not a new
 * platform: FacebookEventsSourceProvisioner rides the same page identifier
 * the 'facebook' source already derived, so there is no connect route and no
 * tile. AU venues post events on Facebook far more than on ticketing
 * platforms, and until this connector the events pool fed ONLY from
 * ticketing (Eventbrite/Humanitix/Luma/DICE/RA).
 *
 * The Pinterest/Instagram vendor pattern: the billed effect is
 * ('vendor', 'facebook_events') on FacebookEventsVendorDriver. CostClass::
 * Actor keeps this off the scheduler by construction (auto_sync=false at
 * provisioning) and eagerOnConnect is the ONE trigger. The daily ceiling is
 * ScrapeCreatorsBudget's 'facebook_events' source cap, claimed per call
 * inside the driver. `hosts` is empty because nothing here fetches Facebook
 * over HTTP.
 *
 * One stream off the vendor envelope:
 *   - `events` (Calendar → events pool): the driver lands the SAME doc shape
 *     Eventbrite and Humanitix land (App\Ingest\Support\SchemaOrgEvent's
 *     output vocabulary, built by FacebookEventDetailsNormalizer), so the
 *     existing SchemaOrgEventProjector serves FB events unchanged — one
 *     projector, no new pool semantics. Coverage honesty is Humanitix's:
 *     exhaustive ONLY when the driver says the walk was complete AND no
 *     scope limit truncated it here; anything partial claims nothing (C5),
 *     because exhaustive + orderField is what tombstones.
 */
class FacebookEventsConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('facebook_events'),
            identifierKind: 'url',
            hosts: [],
            streams: [
                'events' => new StreamSpec(
                    name: 'events',
                    target: 'event',
                    profile: SourceProfile::Calendar,
                    requires: ['name', 'url'],
                    volatile: [],
                    orderField: 'start_date',
                ),
            ],
            cost: CostClass::Actor,
            // The ticketing siblings' 12h cadence — only reachable if the
            // owner lists 'facebook_events' in partna.ingest_scheduled_paid_
            // sources; until then the eager connect run is the only trigger.
            defaultIntervalSeconds: 43200,
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $url = trim($pull->identifier);

        $effect = $io->effect('vendor', 'facebook_events', ['url' => $url]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("facebook_events vendor effect returned status '{$effect['status']}'");

            return;
        }

        $data = is_array($effect['data']) ? $effect['data'] : [];
        $items = [];
        foreach ((array) ($data['events'] ?? []) as $row) {
            $item = is_array($row) ? $this->mapRow($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_events', 'No event docs parsed from the vendor result');

            return;
        }

        $complete = ($data['complete'] ?? false) === true;

        $limit = $pull->scopeLimit();
        if ($limit !== null && count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
            // A truncated list can never claim the whole calendar.
            $complete = false;
        }

        foreach ($items as $item) {
            yield new Record('events', $item['key'], $item['doc']);
        }

        yield new Covered('events', $complete ? Coverage::exhaustive() : Coverage::unknown());
    }

    /**
     * One driver row → one Record. The key is FB's stable event id; the doc
     * is already the shared schema.org-event vocabulary — nothing is reshaped
     * here, only shape-checked so a malformed envelope row cannot land.
     *
     * @param  array<string, mixed>  $row
     * @return array{key: string, doc: array<string, mixed>}|null
     */
    private function mapRow(array $row): ?array
    {
        $key = trim((string) ($row['key'] ?? ''));
        $doc = $row['doc'] ?? null;
        if ($key === '' || ! is_array($doc)) {
            return null;
        }
        if (! is_string($doc['name'] ?? null) || ! is_string($doc['url'] ?? null) || ! is_string($doc['start_date'] ?? null)) {
            return null;
        }

        return ['key' => $key, 'doc' => $doc];
    }
}
