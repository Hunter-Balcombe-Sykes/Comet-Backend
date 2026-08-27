<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\User\User;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolSectionProvisioner;
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
 * Hand-uploaded = pinned, same as a hand-added event: the owner chose this
 * picture; it goes on the site, and the grid's dot menu is where they
 * change their mind.
 */
class ManualMediaWriter
{
    private const POOL = 'media';

    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
    ) {}

    /**
     * Mint (or refresh) the pool item for one uploaded SiteMedia and pin it.
     *
     * @return array{id: string}|null null when the user has no site
     */
    public function add(User $user, SiteMedia $media): ?array
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return null;
        }

        $userId = (string) $user->id;
        $coord = self::coordFor($media);
        $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;

        $caption = trim((string) ($media->caption ?? ''));
        $facets = [
            // Dated at upload time so newest-order treats it as the real,
            // owner-made content it is (X5: dated beats first-seen).
            'f_published' => ['published_from' => now()->toIso8601String()],
        ];
        if ($caption !== '') {
            $facets['f_text'] = ['body' => $caption];
        }

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

        $this->pin($site, $itemId);
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
        $itemId = DB::connection('pgsql')->table('content.item_anchors')
            ->where('user_id', $userId)
            ->where('coord', self::coordFor($media))
            ->value('item_id');
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

    /** Stable per-upload coord on the user's one manual source. */
    private static function coordFor(SiteMedia $media): string
    {
        return 'upload:'.$media->id;
    }

    private function pin(Site $site, string $itemId): void
    {
        $section = $this->sections->ensure($site, self::POOL);
        $sectionId = (string) $section->id;

        $pin = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $pin->section_id = $sectionId;
        $pin->item_id = $itemId;
        $pin->state = SectionItem::STATE_PINNED;
        $pin->sort_key ??= $this->nextSortKey($sectionId);
        if (! $pin->exists) {
            $pin->created_at = now();
        }
        $pin->save();
    }

    private function nextSortKey(string $sectionId): int
    {
        return (int) SectionItem::query()
            ->where('section_id', $sectionId)
            ->max('sort_key') + 1;
    }
}
