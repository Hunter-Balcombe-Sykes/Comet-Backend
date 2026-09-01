<?php

namespace App\Models\Core\Site;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use Database\Factories\Core\Site\SiteFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $user_id Not fillable — set via ->user()->associate() (tenancy FK).
 * @property string $subdomain
 * @property bool $is_published Raw column backing the published accessor/mutator below. For a CLAIMED owner this IS the public-visibility switch: every public read path 404s an unpublished site. The UNCLAIMED carve-out (a pre-account build keeps rendering while unpublished — the pre-claim demo) exists in exactly THREE places, not everywhere: IndividualProfileController and PublicIntegrationController (both since 2026-09-01) and AnalyticsController. PublicSiteResolver, PublicDocumentDownloadController and QrCodeController require is_published unconditionally, so an unclaimed build is dark on those. SyncSubdomainToKvJob ignores the flag entirely and routes regardless, because KV is a routing pointer, not a visibility control. See docs/api.md "Public visibility vs. is_published".
 * @property bool $published Virtual alias for is_published (see getPublishedAttribute/setPublishedAttribute below) — same storage, tolerant boolean parsing on write.
 * @property array<string, mixed> $settings Free-form bag; the 5 FOUND-16 keys (show_branding, charlie_enabled, services_auto_sync_enabled, booking_mode, manual_booking_url) were promoted to real columns and are re-merged at read time (SiteResource) rather than read from here. Known remaining keys: privacy, smart_page_order, manual_page_order, actions, pool_order, pool_locks, display_gallery_page. settings.design.* is REJECTED on write (site.design_kits is the design-var store).
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $subdomain_changed_at
 * @property Carbon|null $unpublished_at
 * @property string $architecture_id One of ARCHITECTURE_IDS ('staple'|'scroll'), CHECK-constrained (sites_architecture_id_check) — plain string, NOT a Postgres enum (see class comment below). Fillable and on the dashboard wire again since 2026-08-24 (reopens the 2026-08-20 lockdown — see UpdateSiteRequest). The public payload derives architectureId from it (IndividualProfileResource).
 * @property string $moderation_state One of 'active'|'warned'|'hidden' (sites_moderation_state_check).
 * @property string|null $custom_domain Lowercase-unique connected FQDN (Cloudflare for SaaS).
 * @property string|null $custom_domain_status One of 'pending'|'active'|'error', or NULL (sites_custom_domain_status_check).
 * @property Carbon|null $custom_domain_verified_at
 * @property string|null $custom_domain_cf_id Cloudflare custom-hostname id (status polling + teardown).
 * @property bool $custom_domain_primary
 * @property bool|null $show_branding FOUND-16 promoted toggle; null == off (mirrors the other toggles below).
 * @property bool|null $charlie_enabled FOUND-16 promoted toggle; null == off.
 * @property bool|null $services_auto_sync_enabled FOUND-16 promoted toggle; null == off.
 * @property string|null $booking_mode One of BOOKING_MODES ('manual'|'none'), or NULL (sites_booking_mode_check) — no DB CHECK enforces the NULL branch's meaning, validated at the request layer.
 * @property string|null $manual_booking_url
 * @property string $shop_link_mode One of SHOP_LINK_MODES ('checkout'|'product'); DEFAULT 'checkout'. Applied to every connected store — no per-brand override.
 * @property-read User|null $user
 * @property-read Workplace|null $workplace 1:1 workplace card (FOUND-4); see workplace() below.
 * @property-read Collection<int, Block> $blocks
 * @property-read Collection<int, SiteVisit> $visits
 * @property-read Collection<int, LinkClick> $clicks
 * @property-read Collection<int, SiteMedia> $siteMedia
 */
// A user's public-facing site. Owns blocks, media, architecture selection, and publish state. One site per user.
// `architecture_id` is a TEXT enum. An "architecture" is how the sitepage is
// laid out / how its pages connect — the renderer (partna-pages) picks a
// code-side architecture from that value. TWO architectures exist as of
// 2026-08-24 ('staple', the platform's original — the DEFAULT moved to
// 'scroll' on 2026-08-27 (owner): migration 20260827120000 flipped the DB
// default and every existing row,
// and 'scroll', a second one added empty — see ARCHITECTURE_IDS); the DB
// CHECK constrains the column to that pair. Per-user design vars live in
// site.design_kits (separate table).
class Site extends BaseModel
{
    use HasFactory, HasUuids;

    /** Default architecture when none has been explicitly chosen. Must match the DB CHECK constraint. */
    public const DEFAULT_ARCHITECTURE_ID = 'scroll';

    /**
     * Allowed architecture ids — mirrors the sites_architecture_id_check DB
     * CHECK constraint. Referenced by UpdateSiteRequest so validation and the
     * DB constraint share a single source of truth, the same convention
     * BOOKING_MODES below follows. Adding an architecture = add here + widen
     * the CHECK (see migration 20260824140000).
     */
    public const ARCHITECTURE_IDS = ['staple', 'scroll'];

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
        'is_published',
        'settings',
        'architecture_id',
        // moderation_state and unpublished_at are deliberately NOT fillable
        // (#SEC-18) — a silently-dropped mass-assignment write here strands a
        // site in the wrong moderation state, or offline/online, with no error.
        // Every writer sets them via explicit assignment (or a query-builder
        // bulk update, which never goes through $fillable at all — see
        // SuspendSiteJob): AccountDeletionService::confirm/cancel, ClaimSiteService.
        // FOUND-16: 5 promoted columns (were settings.* sub-keys). Columns are
        // the source of truth; UpdateSiteAction hoists them out of settings.
        'show_branding',
        'charlie_enabled',
        'services_auto_sync_enabled',
        'booking_mode',
        'manual_booking_url',
        // Content Selection: when true, slots 1–2 are reserved for the latest
        // Instagram reel + post. null == off (mirrors the other toggles above).
        // GLOBAL shop link controls (2026-07-08) — one choice each, applied to
        // every connected store. shop_link_mode: 'checkout'|'product' (the
        // public payload stamps each brand's linkMode from this). shop_auto_latest:
        // every non-individual store auto-tracks its newest products when true.
        'shop_link_mode',
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
        // Global shop auto-latest toggle — clean boolean reads/writes.
    ];

    /** @return BelongsTo<User, $this> */
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
