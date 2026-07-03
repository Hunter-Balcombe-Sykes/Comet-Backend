<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\MediaVariant;
use App\Services\Platforms\Registry\PlatformRegistry;
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

    /**
     * Design-pool singleton purposes — one row per (site, purpose): the two brand
     * logos plus one cover image per cover-capable platform. The cover slots are
     * DERIVED from the platform registry (PlatformDescriptor::isCoverable) so adding
     * a cover for a new platform is a one-line descriptor flag, not a new const +
     * list entry + migration.
     *
     * Registry keys are hyphenated (`apple-music`) but media purposes are underscored
     * (`cover_apple_music`): IndividualProfilePayloadBuilder derives the camelCase
     * `siteImages` wire key by treating `_` as the word boundary, so a hyphen would
     * leak through (`coverApple-music`) and break the partna-pages contract. Hence the
     * convention is `cover_` + the registry key with hyphens normalized to underscores.
     *
     * A const can't call the runtime registry — this is a method by necessity.
     * Enforced at the DB by the composite partial unique index
     * site_media_design_singleton_purpose_uq and the app-side replace in
     * MediaUploadService::uploadSingleton. UploadDesignMediaRequest validates the
     * incoming purpose against this allowlist.
     *
     * @return list<string>
     */
    public static function designSingletonPurposes(): array
    {
        $covers = array_map(
            static fn (string $key): string => 'cover_'.str_replace('-', '_', $key),
            array_keys(app(PlatformRegistry::class)->coverable()),
        );

        return array_merge([self::PURPOSE_LOGO_FULL, self::PURPOSE_LOGO_SQUARE], array_values($covers));
    }

    /**
     * Pool values accepted as a gallery-list filter on GET /api/images, and the
     * pools CacheKeyGenerator::siteImagesViewVariants() enumerates for cache busting.
     * Single source of truth — UserUploadController::index and the cache enumerator
     * both reference this so the bust-key space can never drift from accepted input.
     * Deliberately excludes POOL_DOCUMENTS / POOL_DESIGN (not gallery-listable).
     *
     * @var list<string>
     */
    public const GALLERY_POOLS = [self::POOL_GALLERY, self::POOL_CONTENT];

    public const MEDIA_TYPE_IMAGE = 'image';

    public const MEDIA_TYPE_VIDEO = 'video';

    public const MEDIA_TYPE_DOCUMENT = 'document';

    /**
     * media_type filter inputs accepted on GET /api/images. 'all' is a filter
     * sentinel meaning "no type filter", not a stored media_type — hence the literal.
     *
     * @var list<string>
     */
    public const MEDIA_TYPE_FILTERS = [self::MEDIA_TYPE_IMAGE, self::MEDIA_TYPE_VIDEO, 'all'];

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
                    report($e);
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
                    report($e);
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

    /**
     * Public URL of the vectorized SVG artifact, or null when this media has none
     * (most logos; all covers). Separate from variantUrls(), which is webp-only.
     */
    public function svgVariantUrl(): ?string
    {
        $this->loadMissing('mediaVariants');

        return $this->mediaVariants
            ->firstWhere('artifact_type', 'svg')
            ?->url;
    }

    /**
     * Public URL of the 192px icon PNG artifact (square logos only) — the
     * sitepage favicon/apple-touch source. Null when not generated.
     */
    public function iconVariantUrl(): ?string
    {
        $this->loadMissing('mediaVariants');

        return $this->mediaVariants
            ->firstWhere('variant_key', 'icon')
            ?->url;
    }
}
