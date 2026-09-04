<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\MediaVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $site_id FK → site.sites.id (tenancy — excluded from $fillable; set only via ->site()->associate()).
 * @property string $bucket
 * @property string $path Storage path of the original upload (not a public URL).
 * @property string|null $alt_text
 * @property string|null $caption
 * @property int $sort_order
 * @property bool $is_active
 * @property string $usage What the file is FOR — one of USAGE_* (content|documents|design). NOT a content.* pool: those are public page sections, this is an upload classification. Renamed from `pool` 2026-09-04.
 * @property string $media_type One of MEDIA_TYPE_* (image|video|document).
 * @property string $processing_state One of PROCESSING_STATE_* (pending|processing|ready|failed) — the DB CHECK also allows 'scanning'|'quarantined' for the dormant moderation pipeline (supabase/migrations/20260528020000_alter_site_media_for_scan_states.sql); no class constants exist for those two.
 * @property string|null $processing_error
 * @property string|null $original_mime
 * @property string|null $original_filename
 * @property int|null $original_size_bytes
 * @property int|null $duration_ms
 * @property string|null $poster_path
 * @property string|null $purpose Design-usage slot discriminator — see designSingletonPurposes(). NULL for non-design rows.
 * @property string|null $scanned_at CSAM-scan completion marker (NULL = pre-scanning-era media or not yet scanned). NOT in $casts, so unlike every other timestamp column here this returns a raw driver string, not a Carbon instance.
 * @property string|null $dominant_color #RRGGBB mirror of palette['dominant'].
 * @property array{dominant?: string, colors?: list<string>, saturation?: float, warm?: bool}|null $palette Extracted colour metadata, read by SiteAccentResolver as an accent-colour candidate; NULL until extraction runs or on failure.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Site|null $site
 * @property-read Collection<int, MediaVariant> $mediaVariants
 */
// V2: An uploaded image or video belonging to a site. Tracks processing state (pending/processing/ready/failed) and owns MediaVariant children.
// USAGE_* constants are enforced at the DB level by site_media_usage_check, last redefined in
// @see supabase/migrations/20260624010000_schema_hardening_constraints.sql
class SiteMedia extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.site_media';

    public $incrementing = false;

    protected $keyType = 'string';

    // 'gallery' fully retired: writes 2026-09-01 (Item 5,
    // migration 20260901200000 backfilled every row into USAGE_CONTENT),
    // reads 2026-09-02 (Wave 6 — the legacy read/filter surfaces are gone;
    // PoolGalleryWriteGuardTest pins that the string stays dead).
    public const USAGE_CONTENT = 'content';

    // One downloadable document per site (PDF/JPG/PNG). See
    // docs/superpowers/specs/2026-04-22-document-upload-design.md.
    public const USAGE_DOCUMENTS = 'documents';

    // Singleton brand design assets (logo, placeholder). No ordering semantics —
    // the per-usage sort_order unique index excludes this usage deliberately.
    public const USAGE_DESIGN = 'design';

    // Brand-design slot discriminator inside USAGE_DESIGN. Replaces the old
    // alt_text='logo'|'placeholder' string match — alt_text is now reserved
    // for accessibility text. Set to NULL for non-design rows.
    public const PURPOSE_LOGO_FULL = 'logo_full';

    public const PURPOSE_LOGO_SQUARE = 'logo_square';

    public const PURPOSE_PLACEHOLDER = 'placeholder';

    // T17 (owner, 2026-08-27): a partna professional's OWN profile photo —
    // deliberately separate from the workplace's logo slots per the
    // 2026-08-19 whose-name-is-on-the-door ruling. Auto-seeded at build from
    // the Instagram profile picture (fill-empty only); manually replaceable
    // via the same design-media endpoints; the sitepage uses its icon
    // variant as the partna favicon/touch icon.
    public const PURPOSE_HEADSHOT = 'headshot';

    /**
     * Design-pool singleton purposes — one row per (site, purpose): the two brand
     * logos, the brand placeholder image, and the partna headshot (2026-08-27).
     * Per-platform cover slots
     * (`cover_<key>`, registry-derived via PlatformDescriptor::isCoverable) lived
     * here until 2026-08-05, when the owner retired the whole cover feature —
     * existing cover rows are simply no longer enumerated, validated or served.
     *
     * `placeholder` joined 2026-07-10 (surfaces/content plan): it IS a singleton —
     * the composite unique index site_media_design_singleton_purpose_uq (migration
     * 20260701210000) already enforces one live row per (site, purpose) across the
     * WHOLE design pool, and uploads flow through uploadSingleton's replace-on-upload
     * path — so it rides the same allowlist as a singleton purpose rather than
     * a separate list shape. (Its `siteImages` wire projection died with slice 7
     * unit E; the upload lane and the singleton rule are unchanged.)
     *
     * Enforced at the DB by the composite partial unique index
     * site_media_design_singleton_purpose_uq and the app-side replace in
     * MediaUploadService::uploadSingleton. UploadDesignMediaRequest validates the
     * incoming purpose against this allowlist.
     *
     * @return list<string>
     */
    public static function designSingletonPurposes(): array
    {
        return [self::PURPOSE_LOGO_FULL, self::PURPOSE_LOGO_SQUARE, self::PURPOSE_PLACEHOLDER, self::PURPOSE_HEADSHOT];
    }

    /**
     * Usage values accepted as a list filter on GET /api/images, and the values
     * CacheKeyGenerator::siteImagesViewVariants() enumerates for cache busting.
     * Single source of truth — UserUploadController::index and the cache enumerator
     * both reference this so the bust-key space can never drift from accepted input.
     * Deliberately excludes USAGE_DOCUMENTS / USAGE_DESIGN (not listable here).
     *
     * Down to the one live usage since Wave 6 (2026-09-02): the legacy
     * 'gallery' filter value left with its read surfaces — an old client
     * sending ?pool=gallery now gets the unfiltered list, the same answer
     * as any other unknown value.
     *
     * @var list<string>
     */
    public const LISTABLE_USAGES = [self::USAGE_CONTENT];

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
        // POOL_CONTENT since Item 5 (2026-09-01): a bare save must never
        // recreate a row in the retired gallery lane.
        'usage' => self::USAGE_CONTENT,
        'media_type' => self::MEDIA_TYPE_IMAGE,
        'processing_state' => self::PROCESSING_STATE_PENDING,
    ];

    // site_id is the tenancy FK — deliberately excluded from mass-assignment (SEC-1).
    // Write paths set it via ->site()->associate($site), which bypasses $fillable.
    protected $fillable = [
        'usage',
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
        'dominant_color',
        'palette',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'original_size_bytes' => 'integer',
        'duration_ms' => 'integer',
        'palette' => 'array',
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
                    // No exists() pre-check: on the S3/R2 driver that's a HeadObject
                    // plus a ListObjectsV2 when absent, and it can itself throw
                    // (UnableToCheckFileExistence) into this catch, silently skipping
                    // the delete. DeleteObject on a missing key already succeeds.
                    Storage::disk((string) $variant->disk)->delete($variant->path);
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
                    // See the variant-loop comment above: exists() is dropped for the
                    // same reason here.
                    Storage::disk((string) config('partna.media_disk'))->delete($media->path);
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
     *
     * ONLY the container-sanitised vector variant is ever served. The 2026-07-17
     * fallback that served the ORIGINAL upload when it was itself an SVG is
     * deliberately removed: originals include auto-grabbed SVGs from scanned
     * third-party websites (LogoAutoGrabber), which pass only a regex pre-filter —
     * the container's sanitiser is the real defence, and the fallback fired
     * exactly when that sanitiser had been skipped (GD fallback / pipeline off).
     * A missing vector now degrades to the webp raster instead.
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
