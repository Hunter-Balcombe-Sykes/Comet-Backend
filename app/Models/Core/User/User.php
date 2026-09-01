<?php

namespace App\Models\Core\User;

use App\Enums\AccountType;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\BaseModel;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $auth_user_id Null until claim — pre-account signup leaves it unset on provisional 'unclaimed' users.
 * @property string $handle
 * @property string $handle_lc
 * @property string $display_name
 * @property string|null $bio Owner-authored About Me paragraph (max 1000 app-side); mirrored from workplaces.description for business accounts.
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $country_code
 * @property string|null $timezone
 * @property string|null $phone
 * @property string|null $primary_email Nullable since the pre-account migration (20260718200000) — unset until claim.
 * @property string|null $public_contact_number
 * @property string|null $public_contact_email
 * @property string|null $location_street_address
 * @property string|null $location_postcode
 * @property string|null $location_city
 * @property string|null $location_state
 * @property string|null $location_country
 * @property string $status One of 'active'|'suspended'|'disabled'|'pending_deletion'|'unclaimed' (users_status_check).
 * @property int $onboarding_step
 * @property AccountType $account_type
 * @property string|null $sector Curated industry/sector slug (App\Services\Profile\SectorTaxonomy).
 * @property string|null $sector_source Stamped by the writer: 'manual' (SectorController) or 'google-business' (IdentitySync).
 * @property string|null $partna_url Trigger-managed vanity URL — never mass-assignable.
 * @property string|null $admin_notes Staff-only notes — never expose via UserDashboardResource.
 * @property string|null $deletion_token_hash
 * @property Carbon|null $deletion_requested_at
 * @property Carbon|null $deletion_confirmed_at
 * @property string|null $deletion_previous_status
 * @property Carbon|null $deletion_mail_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at Soft-delete marker (30-day retention).
 * @property-read Site|null $site
 * @property-read PreAccountBuild|null $preAccountBuild
 * @property-read PartnaStaff|null $partnaStaff Independent of account_type — see partnaStaff() below.
 */

// Standalone user model — individual-only accounts. Owns site, services, customers.
class User extends BaseModel
{
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    // DB table is core.users (renamed from core.professionals in the Task 7
    // standalone-user re-baseline). SQLite test DDL (Pest.php) matches.
    protected $table = 'core.users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'auth_user_id',
        'deletion_token_hash',
    ];

    // SEC-2: status, the deletion-lifecycle columns, and admin_notes are
    // server-managed — excluded from mass-assignment. Writers use forceFill()/direct
    // assignment (AccountDeletionService, ClaimSiteService, StaffUserController).
    // account_type and primary_email stay fillable — legitimately validated by
    // UpdateUserRequest/StaffUpdateUserRequest.
    // handle/handle_lc are KEPT fillable (Josh, 2026-07-21): they are already
    // excluded from the /me UpdateUserRequest and changed only via the dedicated
    // rename flow (RenameSubdomainAction forceFill), so removing them bought minimal
    // defence-in-depth for a ~90-test-file blast radius (raw User::create in tests).
    protected $fillable = [
        'handle',
        'handle_lc',
        'display_name',
        'country_code',
        'timezone',
        'account_type',
        'onboarding_step',
        'phone',
        'primary_email',
        'first_name',
        'last_name',

        // Curated industry/sector slug (see App\Services\Profile\SectorTaxonomy).
        // sector_source is deliberately NOT fillable — it's stamped by the
        // SectorController ('manual') and IdentitySync ('google-business').
        'sector',

        // Public Accessible Contacts
        'public_contact_number',
        'public_contact_email',

        // Owner-authored About Me paragraph (2026-08-19 identity plan).
        'bio',

        // Location
        'location_street_address',
        'location_city',
        'location_state',
        'location_postcode',
        'location_country',
    ];

    protected $casts = [
        'onboarding_step' => 'integer',
        'account_type' => AccountType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'deletion_requested_at' => 'datetime',
        'deletion_confirmed_at' => 'datetime',
        'deletion_mail_sent_at' => 'datetime',
    ];

    /**
     * Derive handle_lc from handle at assignment time (D2a).
     *
     * handle_lc is the column every resolver reads (SiteResolver, the public
     * profile cache key, KV sync), so a row where it does not mirror `handle`
     * is invisible to the whole public read path. All four production writers
     * already set both columns; what bit was the OMISSION case — a caller (a
     * factory, a seeder, a manual fix) setting only `handle` and leaving
     * handle_lc at whatever it was.
     *
     * A mutator, not a `saving` hook, deliberately: normalising at assignment
     * means an unsaved model is already correct in memory, so a test asserting
     * on it sees the right value. A `saving` hook would leave that exact case
     * wrong until save() — the shape of the original bug.
     *
     * Cannot fight the existing writers: they set both keys to the same value,
     * forceFill() still routes through setAttribute(), and whichever key lands
     * second writes an identical string.
     *
     * D2b: BOTH sides are trimmed, not just handle_lc. The DB CHECK
     * `handle_lc = lower(handle)` is the constraint this has to satisfy, so the
     * model must make that true BY CONSTRUCTION rather than leave a shape
     * Postgres would reject — otherwise ` Bob ` yields handle_lc `bob` against
     * lower(handle) ` bob ` and the write dies as a 23514 the model happily
     * produced. No behaviour change today: every write path validates
     * `regex:/^[a-z0-9_-]+$/i` (BootstrapRequest, ReclaimHandleRequest, the
     * rename flow), which cannot match whitespace, so the trim is a no-op on
     * every real handle.
     */
    public function setHandleAttribute(?string $value): void
    {
        $this->attributes['handle'] = $value === null ? null : trim($value);
        $this->attributes['handle_lc'] = $value === null ? null : mb_strtolower(trim($value));
    }

    /**
     * Mail-channel routing. Nullable: provisional (unclaimed) users have no email
     * until claim — returning null makes the mail channel skip them instead of
     * fataling (TypeError) inside any queued notification.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->primary_email;
    }

    public function isBusiness(): bool
    {
        return $this->account_type === AccountType::Business;
    }

    // Account is in the post-confirm grace period: read-only HTTP, write-blocked
    // policies. Canonical predicate — middleware and Policies both consult this
    // so the literal status string lives in exactly one place.
    public function isPendingDeletion(): bool
    {
        return mb_strtolower(trim((string) ($this->status ?? ''))) === 'pending_deletion';
    }

    // Account is in the normal, publicly-servable state (not suspended, disabled,
    // or pending deletion). Canonical predicate so the literal status string lives
    // in one place — SyncSubdomainToKvJob retires the subdomain route when false.
    // (The `site.public_site_payload` view this used to cite requires status
    // 'active' OR 'unclaimed' AND is_published — and it backs only the legacy
    // /public/site path, not the profiles route.)
    public function isActive(): bool
    {
        return mb_strtolower(trim((string) ($this->status ?? ''))) === 'active';
    }

    /** Canonical 'unclaimed' predicate (pre-account build; no auth user yet). */
    public function isUnclaimed(): bool
    {
        return mb_strtolower(trim((string) $this->status)) === 'unclaimed';
    }

    /** @return HasOne<Site, $this> */
    public function site(): HasOne
    {
        return $this->hasOne(Site::class, 'user_id');
    }

    public function preAccountBuild(): HasOne
    {
        return $this->hasOne(PreAccountBuild::class, 'user_id');
    }

    // Internal-staff link: a user MAY also be a Partna staff member (the two
    // facts are independent — account_type stays partna/business; staff-ness
    // lives in core.partna_staff). Joined on the shared Supabase auth_user_id,
    // NOT the user id. Read by UserDashboardResource to expose is_staff on /me
    // so the dashboard can switch to the staff surface without account_type
    // ever encoding staff.
    public function partnaStaff(): HasOne
    {
        return $this->hasOne(PartnaStaff::class, 'auth_user_id', 'auth_user_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'user_id');
    }

    public function linkBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'links')
            ->where('block_type', 'link')
            ->orderBy('sort_order');
    }

    public function sectionBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'sections')
            ->orderBy('sort_order');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'user_id')
            ->orderByDesc('created_at');
    }

    public function siteVisits(): HasMany
    {
        return $this->hasMany(SiteVisit::class, 'user_id');
    }

    public function linkClicks(): HasMany
    {
        return $this->hasMany(LinkClick::class, 'user_id');
    }

    public function emailSubscriptions(): HasMany
    {
        return $this->hasMany(EmailSubscription::class, 'user_id');
    }

    /** @return HasMany<IntegrationConnection, $this> */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class, 'user_id');
    }

    // Point HasFactory at the non-namespaced UserFactory so User::factory()
    // resolves correctly regardless of whether it's called from Feature or Unit tests.
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function resolveChildRouteBindingQuery($childType, $value, $field): Builder
    {
        $query = parent::resolveChildRouteBindingQuery($childType, $value, $field);

        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        // Staff endpoints need to bind trashed customers for restore/hard-delete.
        // Service::class is inert here since the services cutover — those routes
        // take a raw string id and resolve it against content.*, so nothing
        // route-binds the model any more. Left in the list rather than removed
        // because the entry is harmless and its absence would read as a
        // deliberate narrowing of the trashed-binding rule.
        if (in_array($childType, [Customer::class, Service::class], true)) {
            $query->withTrashed();
        }

        return $query;
    }
}
