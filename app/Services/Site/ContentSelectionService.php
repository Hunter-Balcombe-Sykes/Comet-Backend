<?php

namespace App\Services\Site;

use App\Models\Core\Site\ContentSelection;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\Platform;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the row logic for a site's "Content Selection" — the ordered list (≤15)
 * of imagery/video a user picks for later use as sitepage backgrounds.
 *
 * NOTHING is copied except manual uploads: google-photo / ig-* entries are
 * stored as references and resolved LIVE against the owner's platform payloads
 * at read time (resolve()), silently dropping any reference that no longer
 * resolves (dangling google ref, disconnected Instagram, empty feed).
 */
class ContentSelectionService
{
    /**
     * The ordered selection resolved for display. Rows are read by position;
     * each is expanded against live media and DROPPED when its reference no
     * longer resolves (dangling google-photo ref, missing Instagram reel/post).
     *
     * Row shapes:
     *   upload       → {id, mediaId, position, kind:'image', type:'upload',       url, badge:null}
     *   google-photo → {id, ref, position, kind:'image', type:'google-photo', url, badge:'google-business'}
     *   ig-reel      → {id, position, kind:'video', type:'ig-reel',      url, poster, badge:'instagram'}
     *   ig-post      → {id, position, kind:'image', type:'ig-post',      url, badge:'instagram'}
     *
     * @return list<array<string, mixed>>
     */
    public function resolve(Site $site): array
    {
        $rows = ContentSelection::query()
            ->where('site_id', $site->id)
            ->orderBy('position')
            ->with('media.mediaVariants')
            ->get();

        // Resolve the two live platform payloads once (lazily reused per row).
        // Null when the owner has no such connection — the matching entry rows
        // are then dropped.
        $googlePhotos = null; // list<array{ref, photoPicUrl}> | null
        $instagram = null;    // InstagramPayload | null

        $resolved = [];

        foreach ($rows as $row) {
            switch ($row->entry_type) {
                case ContentSelection::TYPE_UPLOAD:
                    $url = $row->media instanceof SiteMedia ? $this->mediaUrl($row->media) : null;
                    // Drop if the upload row is gone or has no servable variant yet.
                    if ($url === null) {
                        break;
                    }
                    $resolved[] = [
                        'id' => (string) $row->id,
                        // The site_media id — echoed so the client rebuilds the
                        // PUT entry directly instead of matching on url.
                        'mediaId' => (string) $row->media_id,
                        'position' => $row->position,
                        'kind' => 'image',
                        'type' => ContentSelection::TYPE_UPLOAD,
                        'url' => $url,
                        'badge' => null,
                    ];
                    break;

                case ContentSelection::TYPE_GOOGLE_PHOTO:
                    $googlePhotos ??= $this->googlePhotos($site);
                    $match = $this->findGooglePhoto($googlePhotos, (string) $row->external_ref);
                    // Dangling ref — the photo rotated out of the connection payload.
                    if ($match === null || $match['photoPicUrl'] === null) {
                        break;
                    }
                    $resolved[] = [
                        'id' => (string) $row->id,
                        // The stable Google photo ref — echoed so the client
                        // rebuilds the PUT entry directly instead of url-matching.
                        'ref' => (string) $row->external_ref,
                        'position' => $row->position,
                        'kind' => 'image',
                        'type' => ContentSelection::TYPE_GOOGLE_PHOTO,
                        'url' => $match['photoPicUrl'],
                        'badge' => 'google-business',
                    ];
                    break;

                case ContentSelection::TYPE_IG_REEL:
                    $instagram ??= $this->instagram($site);
                    // Drop if Instagram is disconnected or has no reel.
                    if ($instagram === null || $instagram->videoUrl === null) {
                        break;
                    }
                    $resolved[] = [
                        'id' => (string) $row->id,
                        'position' => $row->position,
                        'kind' => 'video',
                        'type' => ContentSelection::TYPE_IG_REEL,
                        'url' => $instagram->videoUrl,
                        'poster' => $instagram->videoPoster,
                        'badge' => 'instagram',
                    ];
                    break;

                case ContentSelection::TYPE_IG_POST:
                    $instagram ??= $this->instagram($site);
                    $image = $instagram?->images[0] ?? null;
                    // Drop if Instagram is disconnected or has no post image.
                    if (! is_string($image) || $image === '') {
                        break;
                    }
                    $resolved[] = [
                        'id' => (string) $row->id,
                        'position' => $row->position,
                        'kind' => 'image',
                        'type' => ContentSelection::TYPE_IG_POST,
                        'url' => $image,
                        'badge' => 'instagram',
                    ];
                    break;

                case ContentSelection::TYPE_IG_PHOTO:
                    // The ref IS the image URL (R2-mirrored by the Instagram
                    // scrape, so it outlives payload rotation). No payload
                    // membership check — a picked photo shouldn't vanish just
                    // because newer posts pushed it out of the latest-N window.
                    $ref = (string) $row->external_ref;
                    if (! str_starts_with($ref, 'https://')) {
                        break;
                    }
                    $resolved[] = [
                        'id' => (string) $row->id,
                        // Echoed so the client rebuilds the PUT entry directly.
                        'ref' => $ref,
                        'position' => $row->position,
                        'kind' => 'image',
                        'type' => ContentSelection::TYPE_IG_PHOTO,
                        'url' => $ref,
                        'badge' => 'instagram',
                    ];
                    break;
            }
        }

        return $resolved;
    }

    /**
     * Replace the WHOLE selection with the provided ordered list. Positions are
     * assigned 1..n by array order. Validates the count (≤15), the per-entry
     * shape (matching the DB CHECK), and — when Instagram auto is enabled — that
     * any ig-* entry sits only in positions 1–2.
     *
     * Delete-then-insert inside one transaction: the UNIQUE(site_id, position)
     * index would otherwise transiently collide if we tried to shuffle rows in
     * place, so we clear the site's rows first, then insert the new set.
     *
     * @param  list<array{type: string, mediaId?: string|null, ref?: string|null}>  $entries
     *
     * @throws \InvalidArgumentException on a count/shape/placement violation
     */
    public function replace(Site $site, array $entries): void
    {
        $entries = array_values($entries);

        if (count($entries) > ContentSelection::MAX_POSITION) {
            throw new \InvalidArgumentException(
                'A selection may contain at most '.ContentSelection::MAX_POSITION.' entries.'
            );
        }

        $autoEnabled = AutoSyncSetting::isOn((string) $site->user_id, 'instagram');

        $rows = [];
        foreach ($entries as $index => $entry) {
            $position = $index + 1;
            $type = $entry['type'] ?? null;

            if (! in_array($type, ContentSelection::ALL_TYPES, true)) {
                throw new \InvalidArgumentException("Unknown content entry type: {$type}.");
            }

            $mediaId = $entry['mediaId'] ?? null;
            $ref = $entry['ref'] ?? null;

            // Shape validation mirrors the DB CHECK content_selection_ref_shape.
            if ($type === ContentSelection::TYPE_UPLOAD) {
                if (! is_string($mediaId) || $mediaId === '') {
                    throw new \InvalidArgumentException('An upload entry requires a mediaId.');
                }
                $ref = null;
            } elseif ($type === ContentSelection::TYPE_GOOGLE_PHOTO || $type === ContentSelection::TYPE_IG_PHOTO) {
                if (! is_string($ref) || $ref === '') {
                    throw new \InvalidArgumentException("A {$type} entry requires a ref.");
                }
                $mediaId = null;
            } else {
                // ig-reel / ig-post carry neither reference.
                $mediaId = null;
                $ref = null;
            }

            // When auto is on, the ig-* entries are pinned to slots 1–2. Any
            // ig-* entry deeper than position 2 is a client error.
            if ($autoEnabled
                && in_array($type, ContentSelection::IG_TYPES, true)
                && $position > 2) {
                throw new \InvalidArgumentException(
                    'Instagram entries must occupy positions 1–2 while auto is enabled.'
                );
            }

            $rows[] = [
                'site_id' => $site->id,
                'position' => $position,
                'entry_type' => $type,
                'media_id' => $mediaId,
                'external_ref' => $ref,
            ];
        }

        $this->persist($site, $rows);
    }

    /**
     * Flip the Instagram auto flag on the site and reconcile the reserved slots.
     *
     * Enabling: ensure an ig-reel at position 1 and ig-post at position 2 (only
     * for the kinds the owner actually has — skip a reel if there's no reel,
     * skip a post if there's no post), shifting existing manual entries down and
     * dropping anything past the cap. Disabling: remove all ig-* rows and
     * compact the remaining entries to 1..n. Best-effort and safe if Instagram
     * is absent (enabling with no reel/post simply reserves nothing).
     */
    public function setInstagramAuto(Site $site, bool $enabled): void
    {
        // Flag flip + slot reconciliation must commit atomically: persist()
        // opens its own transaction, which nests as a SAVEPOINT here. Without
        // this wrap a persist() failure left the flag durably flipped with no
        // slots reserved/cleared to match.
        DB::connection('pgsql')->transaction(function () use ($site, $enabled) {
            // 2026-08-05: the flag lives on the instagram connection's own
            // display_settings now (one toggle grammar, AutoSyncSetting).
            AutoSyncSetting::set((string) $site->user_id, 'instagram', $enabled);

            $existing = ContentSelection::query()
                ->where('site_id', $site->id)
                ->orderBy('position')
                ->get();

            if (! $enabled) {
                // Drop ig-* rows, compact the rest.
                $kept = $existing
                    ->reject(fn (ContentSelection $r) => in_array($r->entry_type, ContentSelection::IG_TYPES, true))
                    ->values();

                $this->persist($site, $this->rowsFromModels($site, $kept));

                return;
            }

            // Enabling: figure out which ig kinds the owner can actually fill.
            $instagram = $this->instagram($site);
            $reserve = [];
            if ($instagram !== null && $instagram->videoUrl !== null) {
                $reserve[] = ContentSelection::TYPE_IG_REEL;
            }
            if ($instagram !== null && ($instagram->images[0] ?? null)) {
                $reserve[] = ContentSelection::TYPE_IG_POST;
            }

            // Manual (non-ig) entries keep their relative order, shifted below the
            // reserved slots. Drop any pre-existing ig-* rows — we re-seed them.
            $manual = $existing
                ->reject(fn (ContentSelection $r) => in_array($r->entry_type, ContentSelection::IG_TYPES, true))
                ->values();

            $rows = [];
            $position = 1;
            foreach ($reserve as $type) {
                $rows[] = [
                    'site_id' => $site->id,
                    'position' => $position++,
                    'entry_type' => $type,
                    'media_id' => null,
                    'external_ref' => null,
                ];
            }
            foreach ($manual as $row) {
                if ($position > ContentSelection::MAX_POSITION) {
                    break; // overflow past the cap is dropped
                }
                $rows[] = [
                    'site_id' => $site->id,
                    'position' => $position++,
                    'entry_type' => $row->entry_type,
                    'media_id' => $row->media_id,
                    'external_ref' => $row->external_ref,
                ];
            }

            $this->persist($site, $rows);
        });
    }

    /**
     * One-time seed: if the selection has NO upload/google-photo rows (ig-*
     * rows don't count), populate google-photo rows from the owner's Google
     * Business photos in payload order, up to the cap. Callers invoke this on
     * GB connect only — it is a no-op once the user has any real content pick.
     */
    public function maybeSeedFromGoogle(Site $site): void
    {
        $hasRealContent = ContentSelection::query()
            ->where('site_id', $site->id)
            ->whereIn('entry_type', [ContentSelection::TYPE_UPLOAD, ContentSelection::TYPE_GOOGLE_PHOTO])
            ->exists();

        if ($hasRealContent) {
            return;
        }

        $photos = $this->googlePhotos($site);
        if ($photos === []) {
            return;
        }

        // Preserve any existing ig-* rows (auto slots) at the front, then append
        // google-photo rows after them, respecting the cap.
        $existingIg = ContentSelection::query()
            ->where('site_id', $site->id)
            ->whereIn('entry_type', ContentSelection::IG_TYPES)
            ->orderBy('position')
            ->get();

        $rows = $this->rowsFromModels($site, $existingIg);
        $position = count($rows) + 1;

        foreach ($photos as $photo) {
            if ($position > ContentSelection::MAX_POSITION) {
                break;
            }
            $rows[] = [
                'site_id' => $site->id,
                'position' => $position++,
                'entry_type' => ContentSelection::TYPE_GOOGLE_PHOTO,
                'media_id' => null,
                'external_ref' => $photo['ref'],
            ];
        }

        $this->persist($site, $rows);
    }

    /**
     * Transactionally replace the site's rows: delete existing, insert the new
     * ordered set. The delete-then-insert avoids the UNIQUE(site_id, position)
     * transient collisions an in-place re-numbering would hit. Positions in
     * $rows are already assigned 1..n by the caller.
     *
     * site_id is not fillable (tenancy FK) — forceCreate() so the row still
     * persists it, since every $row here is server-built with $row['site_id']
     * already set to $site->id.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(Site $site, array $rows): void
    {
        DB::connection('pgsql')->transaction(function () use ($site, $rows) {
            ContentSelection::query()->where('site_id', $site->id)->delete();

            foreach ($rows as $row) {
                ContentSelection::forceCreate($row);
            }
        });
    }

    /**
     * Map persisted ContentSelection models back to the insert-row shape,
     * RE-NUMBERING positions to a compact 1..n in their current order. Used when
     * dropping rows (disable auto) or preserving a subset (seed) so the surviving
     * rows never leave a gap that would offend the position CHECK/UNIQUE.
     *
     * @param  Collection<int, ContentSelection>  $models
     * @return list<array<string, mixed>>
     */
    private function rowsFromModels(Site $site, $models): array
    {
        $rows = [];
        $position = 1;
        foreach ($models as $model) {
            $rows[] = [
                'site_id' => $site->id,
                'position' => $position++,
                'entry_type' => $model->entry_type,
                'media_id' => $model->media_id,
                'external_ref' => $model->external_ref,
            ];
        }

        return $rows;
    }

    /**
     * The public URL for a content-pool upload. Reuses the gallery/sitepage
     * convention (SitepageDataResolverService): prefer the optimized WebP
     * variant, fall back to maximized. Null when no servable variant exists yet
     * (still processing / failed) — the caller drops the row.
     */
    private function mediaUrl(SiteMedia $media): ?string
    {
        $variants = $media->variantUrls();

        $url = $variants['optimized'] ?? $variants['maximized'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The owner's Google Business photos, normalised to { ref, photoPicUrl },
     * read through the typed payload DTO (no raw ->payload access). Empty when
     * the owner has no active google-business connection.
     *
     * @return list<array{ref: string, photoPicUrl: string|null}>
     */
    private function googlePhotos(Site $site): array
    {
        $conn = $this->connectionFor($site, Platform::GoogleBusiness->value);
        if ($conn === null || ! self::googlePhotosEnabled($conn->display_settings)) {
            return [];
        }

        return GoogleBusinessPayload::fromArray($conn->payload)->photos();
    }

    /**
     * Whether Google photos may flow into the content pipeline (media library +
     * selection). Stored as `content_photos` in the google-business connection's
     * display_settings (WS-B2.1) — the same sparse map the display toggles use;
     * absent/true = included, an explicit false excludes. Kept distinct from the
     * `photos` display toggle (which governs the sitepage + dashboard GB card) so
     * the two surfaces are controlled independently. Off makes resolve() drop
     * every google-photo pick (their refs no longer resolve) AND makes
     * maybeSeedFromGoogle() seed nothing.
     *
     * @param  array<string, mixed>|null  $displaySettings
     */
    public static function googlePhotosEnabled(?array $displaySettings): bool
    {
        return ($displaySettings['content_photos'] ?? true) !== false;
    }

    /**
     * The owner's Instagram payload (latest post images + reel), through the
     * typed DTO. Null when there's no active instagram connection.
     */
    private function instagram(Site $site): ?InstagramPayload
    {
        $conn = $this->connectionFor($site, Platform::Instagram->value);
        if ($conn === null) {
            return null;
        }

        return InstagramPayload::fromArray($conn->payload);
    }

    /**
     * Locate the site owner's active connection for a platform. Sites are 1:1
     * with users; connections carry user_id, so we resolve through the owner.
     */
    private function connectionFor(Site $site, string $platform): ?IntegrationConnection
    {
        $userId = $site->user_id;
        if ($userId === null) {
            return null;
        }

        return IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Find a google photo by its stable ref within the resolved photo list.
     *
     * @param  list<array{ref: string, photoPicUrl: string|null}>  $photos
     * @return array{ref: string, photoPicUrl: string|null}|null
     */
    private function findGooglePhoto(array $photos, string $ref): ?array
    {
        foreach ($photos as $photo) {
            if ($photo['ref'] === $ref) {
                return $photo;
            }
        }

        return null;
    }
}
