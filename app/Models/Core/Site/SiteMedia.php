<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\MediaVariant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// V2: An uploaded image or video belonging to a site. Tracks processing state (pending/processing/ready/failed) and owns MediaVariant children.
// POOL_* constants are enforced at the DB level. @see supabase/migrations/202605190000002_add_enum_check_constraints.sql
class SiteMedia extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.site_media';

    public $incrementing = false;

    protected $keyType = 'string';

    public const POOL_GALLERY = 'gallery';

    public const POOL_CONTENT = 'content';

    // One downloadable document per site (PDF/JPG/PNG). See
    // docs/superpowers/specs/2026-04-22-document-upload-design.md.
    public const POOL_DOCUMENTS = 'documents';

    // Singleton brand design assets (logo, placeholder). No ordering semantics —
    // the per-pool sort_order unique index excludes this pool deliberately.
    public const POOL_DESIGN = 'design';

    // Brand-design slot discriminator inside POOL_DESIGN. Replaces the old
    // alt_text='logo'|'placeholder' string match — alt_text is now reserved
    // for accessibility text. Set to NULL for non-design rows.
    public const PURPOSE_LOGO_FULL = 'logo_full';

    public const PURPOSE_LOGO_SQUARE = 'logo_square';

    public const PURPOSE_PLACEHOLDER = 'placeholder';

    public const MEDIA_TYPE_IMAGE = 'image';

    public const MEDIA_TYPE_VIDEO = 'video';

    public const MEDIA_TYPE_DOCUMENT = 'document';

    public const PROCESSING_STATE_PENDING = 'pending';

    public const PROCESSING_STATE_PROCESSING = 'processing';

    public const PROCESSING_STATE_READY = 'ready';

    public const PROCESSING_STATE_FAILED = 'failed';

    protected $attributes = [
        'is_active' => true,
        'pool' => self::POOL_GALLERY,
        'media_type' => self::MEDIA_TYPE_IMAGE,
        'processing_state' => self::PROCESSING_STATE_PENDING,
    ];

    protected $fillable = [
        'site_id',
        'pool',
        'bucket',
        'path',
        'alt_text',
        'caption',
        'purpose',
        'sort_order',
        'is_active',
        'media_type',
        'processing_state',
        'processing_error',
        'original_mime',
        'original_filename',
        'original_size_bytes',
        'duration_ms',
        'poster_path',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'original_size_bytes' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Lifecycle hooks */
    /* ------------------------------------------------------------------ */

    protected static function booted(): void
    {
        // Collect variant storage paths BEFORE forceDelete fires — the DB cascade
        // wipes media_variants rows at the same time the parent row is deleted,
        // so forceDeleted (after-event) would find an empty relation.
        static::forceDeleting(function (SiteMedia $media): void {
            // Delete processed variants (each row tracks its own disk).
            $variantPaths = $media->mediaVariants()
                ->whereNotNull('path')
                ->get(['disk', 'path']);

            foreach ($variantPaths as $variant) {
                try {
                    $disk = Storage::disk((string) $variant->disk);
                    if ($disk->exists($variant->path)) {
                        $disk->delete($variant->path);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete variant file during SiteMedia force-delete', [
                        'media_id' => $media->id,
                        'disk' => $variant->disk,
                        'path' => $variant->path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Also delete the original upload. SiteMedia has no disk column — the
            // original always lives on the configured media disk (same as purgeDocumentArtifact).
            if ($media->path) {
                try {
                    $mediaDisk = Storage::disk((string) config('partna.media_disk'));
                    if ($mediaDisk->exists($media->path)) {
                        $mediaDisk->delete($media->path);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete original file during SiteMedia force-delete', [
                        'media_id' => $media->id,
                        'path' => $media->path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function mediaVariants(): HasMany
    {
        return $this->hasMany(MediaVariant::class, 'media_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Return [variant_key => public_url] map for image media items.
     * Filters to artifact_type='webp' from the already-loaded mediaVariants relation.
     *
     * @return array<string, string>
     */
    public function variantUrls(): array
    {
        $this->loadMissing('mediaVariants');

        return $this->mediaVariants
            ->filter(fn (MediaVariant $v) => $v->artifact_type === 'webp')
            ->mapWithKeys(fn (MediaVariant $v) => [$v->variant_key => $v->url])
            ->all();
    }
}
