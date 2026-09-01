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
 * Pinterest via the ScrapeCreators vendor lane (Item 10a, 2026-09-01) — the
 * Instagram pattern with one substitution: the billed effect is
 * ('vendor', 'pinterest') on PinterestVendorDriver, because pinterest has no
 * Apify actor at all. CostClass::Actor still applies — "third-party billed
 * per invocation" is what keeps this off the scheduler by construction
 * (auto_sync=false at provisioning), and eagerOnConnect is the ONE trigger,
 * exactly the InstagramConnector contract. The daily ceiling is
 * ScrapeCreatorsBudget's 'pinterest' source cap, claimed per call inside the
 * driver. `hosts` is empty because nothing here fetches Pinterest over HTTP.
 *
 * One stream off the vendor rows:
 *   - `pins` (Sample → media pool): image pins from the account's public
 *     boards, board-curation order. Sample, not Feed, and orderField null,
 *     because board pins carry NO dates (created_at is null on the recorded
 *     payload) and a board walk is a vendor-shaped sample of the account —
 *     so absence never means deletion, which is the only honest claim.
 *     Coverage is Coverage::unknown() for the same reason (the
 *     GoogleBusiness media precedent). pinimg URLs are unsigned and stable —
 *     no volatile entries — but the pool still mirrors bytes to R2 under
 *     `pinterest:` refs (owned-bytes policy), never hot-links.
 */
class PinterestConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('pinterest'),
            identifierKind: 'username',
            hosts: [],
            streams: [
                'pins' => new StreamSpec(
                    name: 'pins',
                    target: 'media',
                    profile: SourceProfile::Sample,
                    requires: ['id'],
                ),
            ],
            cost: CostClass::Actor,
            defaultIntervalSeconds: 604800,
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $username = strtolower(ltrim(trim($pull->identifier), '@'));

        $effect = $io->effect('vendor', 'pinterest', ['username' => $username]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("pinterest vendor effect returned status '{$effect['status']}'");

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapPin($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_pins', 'No pins parsed from the vendor result');

            return;
        }

        // NO recency re-sort, unlike Instagram/TikTok: pins carry no dates,
        // and the driver's row order IS the board curation order.
        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('pins', $item['id'], $item);
        }

        yield new Covered('pins', Coverage::unknown());
    }

    /** @return array<string, mixed>|null */
    private function mapPin(array $row): ?array
    {
        $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
        $image = is_string($row['image'] ?? null) ? trim($row['image']) : '';
        if (preg_match('/^\d+$/', $id) !== 1 || $image === '') {
            return null;
        }

        return array_filter([
            'id' => $id,
            'title' => is_string($row['title'] ?? null) ? $row['title'] : null,
            'caption' => is_string($row['caption'] ?? null) ? $row['caption'] : null,
            'url' => is_string($row['url'] ?? null) && trim($row['url']) !== ''
                ? trim($row['url'])
                : 'https://www.pinterest.com/pin/'.$id.'/',
            'image' => $image,
            'video_url' => is_string($row['video_url'] ?? null) ? $row['video_url'] : null,
            'duration' => is_numeric($row['duration'] ?? null) && (int) $row['duration'] > 0 ? (int) $row['duration'] : null,
            'board_id' => is_string($row['board_id'] ?? null) ? $row['board_id'] : null,
            'board_name' => is_string($row['board_name'] ?? null) ? $row['board_name'] : null,
        ], static fn ($v) => $v !== null);
    }
}
