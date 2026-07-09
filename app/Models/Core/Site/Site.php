<?php

namespace App\Models\Core\Site;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use Database\Factories\Core\Site\SiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * @property mixed $id
 */
// A user's public-facing site. Owns blocks, media, skeleton selection, and publish state. One site per user.
// `skeleton_id` is a TEXT enum constrained by the DB CHECK to bento/dock/flick/deck/atlas/one
// — the renderer (partna-pages) picks a code-side skeleton layout from that
// value (thread/sheet removed 2026-07-08 #78; atlas = Business-only multi-page;
// 'one' reserved for the upcoming ONE skeleton — accepted on write, not yet
// rendered or picker-exposed, both land in V1). Per-user design vars live in
// site.design_kits (separate table).
class Site extends BaseModel
{
    use HasFactory, HasUuids;

    /** Default skeleton when none has been explicitly chosen. Must match the DB CHECK constraint. */
    public const DEFAULT_SKELETON_ID = 'bento';

    /**
     * Allowed GLOBAL shop link modes — mirrors the value the shop-settings
     * request validates (no DB CHECK, matching the SQLite-test-mirror
     * convention). 'checkout' deep-links product cards straight to the store
     * cart/checkout; 'product' links to the product page. Applied to EVERY
     * connected store — the public payload stamps each brand's linkMode from
     * site.sites.shop_link_mode. Default 'checkout' (direct-to-checkout ON).
     */
    public const SHOP_LINK_MODES = ['checkout', 'product'];

    /** Global shop link-mode default when the column is unset (fresh row). */
    public const DEFAULT_SHOP_LINK_MODE = 'checkout';

    /**
     * Allowed booking modes — mirrors the sites_booking_mode_check DB CHECK
     * constraint. Referenced by UpdateSiteRequest / StaffUpdateSiteRequest /
     * UpdateBookingSettingsRequest so validation and the DB constraint share a
     * single source of truth. Adding a mode = add here + widen the CHECK.
     */
    public const BOOKING_MODES = ['manual', 'none'];

    /**
     * settings JSONB sub-keys promoted to typed columns (FOUND-16). The column
     * name equals the settings key in every case. Used by UpdateSiteAction
     * (hoist) and SiteResource (re-merge for byte-identical responses).
     */
    public const PROMOTED_SETTINGS_KEYS = [
        'show_branding',
        'charlie_enabled',
        'services_auto_sync_enabled',
        'booking_mode',
        'manual_booking_url',
    ];

    protected $table = 'site.sites';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'subdomain',
        'skeleton_id',
        'is_published',
        'unpublished_at',
        'settings',
        'moderation_state',
        // FOUND-16: 5 promoted columns (were settings.* sub-keys). Columns are
        // the source of truth; UpdateSiteAction hoists them out of settings.
        'show_branding',
        'charlie_enabled',
        'services_auto_sync_enabled',
        'booking_mode',
        'manual_booking_url',
        // Content Selection: when true, slots 1–2 are reserved for the latest
        // Instagram reel + post. null == off (mirrors the other toggles above).
        'content_instagram_auto_enabled',
        // GLOBAL shop link controls (2026-07-08) — one choice each, applied to
        // every connected store. shop_link_mode: 'checkout'|'product' (the
        // public payload stamps each brand's linkMode from this). shop_auto_latest:
        // every non-individual store auto-tracks its newest products when true.
        'shop_link_mode',
        'shop_auto_latest',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'settings' => 'array',
        'subdomain_changed_at' => 'datetime',
        'unpublished_at' => 'datetime',
        'custom_domain_verified_at' => 'datetime',
        'custom_domain_primary' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        // FOUND-16 promoted booleans.
        'show_branding' => 'boolean',
        'charlie_enabled' => 'boolean',
        'services_auto_sync_enabled' => 'boolean',
        // null == off; cast so reads/writes are clean booleans.
        'content_instagram_auto_enabled' => 'boolean',
        // Global shop auto-latest toggle — clean boolean reads/writes.
        'shop_auto_latest' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // 1:1 workplace card — promoted from settings->'workplace' JSONB (FOUND-4).
    // A null result means no workplace row exists; callers check ->workplace->name before rendering.
    public function workplace(): HasOne
    {
        return $this->hasOne(Workplace::class, 'site_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'site_id')
            ->orderBy('sort_order');
    }

    public function linkBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'links')
            ->orderBy('sort_order');
    }

    public function sectionBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'sections')
            ->orderBy('sort_order');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(SiteVisit::class, 'site_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class, 'site_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function siteMedia(): HasMany
    {
        return $this->hasMany(SiteMedia::class, 'site_id');
    }

    /**
     * The site's stored design-kit vars as a non-null partial
     * (column-per-var row in site.design_kits; there is no Eloquent model —
     * writes go through UserSiteController::writeDesignKit's raw builder, so
     * the read mirrors it). Bookkeeping columns are stripped; null columns are
     * omitted, matching the public profile payload's "partial kit" convention.
     *
     * @return array<string, mixed>
     */
    public function designKitVars(): array
    {
        try {
            $row = DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $this->id)
                ->first();
        } catch (\Throwable) {
            // Fail-closed to "no stored vars": the editor falls back to package
            // defaults exactly as before this field existed. Also covers the
            // SQLite test mirror, which doesn't create site.design_kits.
            return [];
        }

        if ($row === null) {
            return [];
        }

        $vars = (array) $row;
        unset($vars['site_id'], $vars['created_at'], $vars['updated_at']);

        return array_filter($vars, static fn ($value): bool => $value !== null);
    }

    public function getPublishedAttribute(): bool
    {
        return (bool) ($this->attributes['is_published'] ?? false);
    }

    public function setPublishedAttribute($value): void
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $bool = $bool ?? (bool) $value;

        // Otherwise store in is_published
        $this->attributes['is_published'] = $bool;
    }

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }
}
