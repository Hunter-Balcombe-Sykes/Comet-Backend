<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Support\Facades\DB;

/**
 * The upload→pool bridge (plan 04 step E, 2026-08-27). A dashboard media
 * upload lands as a SiteMedia row (POOL_CONTENT) with its own variant
 * pipeline — but the media POOL renders content.items, so without an item
 * the upload was invisible to the grid, the picker, and the sitepage
 * gallery. This writer mints that item through the SAME manual lane the
 * hand-add flows use (ManualEventWriter idiom: writeManualItem →
 * un-delete → pin → bust), with the upload-shaped media entry the
 * projection writer was built to accept (slice 1a §3.4: a non-empty
 * `site_media_id` IS the upload discriminator — no mirror fetch, the bytes
 * are already ours).
 *
 * Hand-uploaded = LIBRARY-ONLY (owner, 2026-08-27, reversing the launch
 * "pinned like a hand-added event" rule): an add-sheet upload lands as the
 * top unselected option in that sheet, and putting it ON the site stays an
 * explicit second choice. The pin lane was deleted with the rule — selection
 * goes through the pools' own selectPoolItem endpoint.
 *
 * Website grabs ride the same bridge since Item 5 (2026-09-01, POOL_GALLERY
 * retired): GalleryAutoGrabber lands bytes as POOL_CONTENT and mints its
 * items here under the 'website:' origin — same unpinned library semantics
 * as an upload, provenance carried in the coord namespace.
 */
class ManualMediaWriter
{
    /**
     * Coord namespaces double as the pool item's provenance tag (Item 5,
     * 2026-09-01): connection-synced items carry their source through
     * content.sources.source_key, but manual-source items have only the
     * anchor coord to say where their bytes came from. 'upload:' = an
     * owner upload; 'website:' = a previous-website grab
     * (GalleryAutoGrabber) — same byte lane (site_media POOL_CONTENT),
     * different provenance.
     */
    public const ORIGIN_UPLOAD = 'upload';

    public const ORIGIN_WEBSITE = 'website';

    public function __construct(
        private readonly ProjectionWriter $writer,
    ) {}

    /**
     * Mint (or refresh) the library pool item for one uploaded SiteMedia.
     *
     * @return array{id: string}|null null when the user has no site
     */
    public function add(User $user, SiteMedia $media, string $origin = self::ORIGIN_UPLOAD): ?array
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return null;
        }

        $userId = (string) $user->id;
        $coord = self::coordFor($media, $origin);
        $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;

        $facets = [
            // Dated at upload time so newest-order treats it as the real,
            // owner-made content it is (X5: dated beats first-seen).
            // Deliberately NO f_text facet, even when the media row carries a
            // caption: step D made media items caption-less on the dashboard,
            // and a text facet here would resurrect the field through the
            // back door (critic finding 10). alt/caption live on SiteMedia.
            'f_published' => ['published_from' => now()->toIso8601String()],
        ];

        $entry = array_filter([
            'role' => $isVideo ? 'video' : 'cover',
            // The upload shape: site_media_id is the discriminator (the
            // media row's variants carry the real dims; MediaUrlResolver
            // serves them).
            'site_media_id' => (string) $media->id,
            'mime_type' => $media->original_mime,
        ], static fn ($v) => $v !== null && $v !== '');

        $itemId = $this->writer->writeManualItem($userId, $coord, [
            'kind' => 'media',
            'headline' => null,
            'facets' => $facets,
            'media' => [$entry],
        ]);

        // An explicit re-upload un-deletes, exactly as the hand-add lanes do.
        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->whereNotNull('removed_at')
            ->update(['removed_at' => null, 'updated_at' => now()]);

        SiteCacheLanes::bust([(string) $site->id]);

        return ['id' => $itemId];
    }

    /**
     * The upload's item leaves the pool with it (dashboard delete): the
     * user-level removal PoolResolver filters on, not a hard delete — the
     * projection rows follow their own retention.
     */
    public function remove(User $user, SiteMedia $media): void
    {
        $userId = (string) $user->id;

        try {
            // site_media does not record which origin bridged a row, so the
            // delete lane probes every manual namespace — at most one coord
            // exists per media id (each origin mints exactly once).
            $itemId = DB::connection('pgsql')->table('content.item_anchors')
                ->where('user_id', $userId)
                ->whereIn('coord', [
                    self::coordFor($media, self::ORIGIN_UPLOAD),
                    self::coordFor($media, self::ORIGIN_WEBSITE),
                ])
                ->value('item_id');
        } catch (\Throwable) {
            // Fail-open ONLY here: a lookup that throws means the content
            // lane itself is absent (partial test envs), so no bridged item
            // exists to strand. A failure while marking a FOUND item removed
            // (below) propagates — the caller must not delete the bytes a
            // live item still points at.
            return;
        }
        if ($itemId === null) {
            return;
        }

        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->update(['removed_at' => now(), 'updated_at' => now()]);

        $site = $user->site;
        if ($site instanceof Site) {
            SiteCacheLanes::bust([(string) $site->id]);
        }
    }

    /** Stable per-media coord on the user's one manual source; the namespace carries provenance. */
    private static function coordFor(SiteMedia $media, string $origin): string
    {
        return $origin.':'.$media->id;
    }
}
