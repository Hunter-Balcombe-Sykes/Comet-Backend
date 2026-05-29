<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A single processed variant or artifact row in core.media_variants.
 *
 * Images (artifact_type='webp'):
 *   - variant_key='optimized'  + artifact_type='webp'         → optimised WebP
 *   - variant_key='maximized'  + artifact_type='webp'         → full-quality WebP
 *
 * Videos (new uploads, 2026-05-29+):
 *   - variant_key='optimized'  + artifact_type='mp4'    → 720p MP4 (autoplay default)
 *   - variant_key='maximized'  + artifact_type='mp4'    → 1080p MP4 (on-demand / fullscreen)
 *   - variant_key='poster'     + artifact_type='poster' → poster JPEG
 *
 * Legacy (pre-2026-05-29) videos may still carry artifact_type='hls_playlist'
 * rows + hls/ segment files; these are no longer produced, but deleteVariants()
 * stays format-agnostic so they remain cleanable.
 *
 * @property string $id
 * @property string $media_id FK → site_media.id
 * @property string $variant_key Logical tier: optimized|maximized|poster (legacy rows may also be 'adaptive')
 * @property string $artifact_type Physical format: mp4|poster (legacy rows may also be 'hls_playlist')
 * @property string $disk
 * @property string $path Storage path (not a public URL)
 * @property string|null $mime
 * @property int|null $width
 * @property int|null $height
 * @property int|null $bitrate_kbps
 * @property int|null $file_size_bytes
 * @property int|null $duration_ms
 * @property array|null $metadata
 */
// V2: Processed media artifact (WebP image, MP4 video, poster). Each SiteMedia can have multiple variants at different quality tiers.
//
// Lifecycle: wholly owned by parent SiteMedia. SiteMedia::booted() forceDeleting hook collects
// variant paths and deletes storage files before the DB CASCADE removes these rows.
// Do not call MediaVariant::delete() or forceDelete() directly — always delete via the parent.
class MediaVariant extends BaseModel
{
    use HasUuids;

    protected $table = 'site.media_variants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'media_id',
        'variant_key',
        'artifact_type',
        'disk',
        'path',
        'mime',
        'width',
        'height',
        'bitrate_kbps',
        'file_size_bytes',
        'duration_ms',
        'metadata',
        'content_hash',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'bitrate_kbps' => 'integer',
        'file_size_bytes' => 'integer',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function media(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'media_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    /**
     * Public URL for this artifact (CDN-friendly).
     *
     * Fast-path string concatenation when the disk has an explicit `url`
     * configured (the production case for our `media` disk). The slow path
     * via `Storage::disk()->url()` lazily constructs an S3 client on first
     * call — ~150–300ms of AWS SDK init that previously happened on every
     * cache-miss `/api/images` response, multiplied by every variant URL
     * resolved in the response. The fast path avoids that entirely; the
     * fallback is preserved so disks without a configured URL (e.g. local
     * development without MEDIA_DISK_URL set, or any future disk that needs
     * presigning) keep working.
     */
    public function getUrlAttribute(): string
    {
        $disk = (string) $this->disk;
        if ($disk !== '') {
            $baseUrl = config("filesystems.disks.{$disk}.url");
            if (is_string($baseUrl) && $baseUrl !== '') {
                return rtrim($baseUrl, '/').'/'.ltrim((string) $this->path, '/');
            }
        }

        // Fallback: lazy adapter resolution. If the disk isn't configured
        // (e.g. variants written under an old Laravel Cloud disk name that
        // no longer matches LARAVEL_CLOUD_DISK_CONFIG, or a non-existent
        // disk altogether) Storage::disk() throws InvalidArgumentException.
        // Catching it here keeps a single broken variant from 500ing the
        // whole /brand/design response — the controller surfaces the row
        // with an empty URL and the dashboard renders an empty card.
        try {
            /** @var FilesystemAdapter $adapter */
            $adapter = Storage::disk($disk);

            return $adapter->url($this->path);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('MediaVariant::getUrlAttribute failed to resolve disk URL.', [
                'media_id' => $this->media_id,
                'variant_id' => $this->id,
                'variant_key' => $this->variant_key,
                'disk' => $disk,
                'path' => $this->path,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
