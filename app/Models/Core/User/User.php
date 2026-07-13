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

/**
 * @property string $id
 * @property string $auth_user_id
 * @property string $handle
 * @property string $display_name
 * @property int $onboarding_step
 * @property string|null $partna_url Trigger-managed vanity URL — never mass-assignable.
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

    protected $fillable = [
        'handle',
        'display_name',
        'country_code',
        'timezone',
        'account_type',
        'status',
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

        // Location
        'location_street_address',
        'location_city',
        'location_state',
        'location_postcode',
        'location_country',

        'handle_lc',

        // Account deletion lifecycle
        'deletion_token_hash',
        'deletion_requested_at',
        'deletion_confirmed_at',
        'deletion_previous_status',
        'deletion_mail_sent_at',

        // Staff-only — surfaced through UserStaffResource. Never expose through
        // UserDashboardResource (self-service /me).
        'admin_notes',
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

    /** Route mail notifications to the user's primary email address. */
    public function routeNotificationForMail(): string
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
    // in one place — SyncSubdomainToKvJob retires the subdomain route when false,
    // mirroring the public read path (public_site_payload requires status='active').
    public function isActive(): bool
    {
        return mb_strtolower(trim((string) ($this->status ?? ''))) === 'active';
    }

    public function site(): HasOne
    {
        return $this->hasOne(Site::class, 'user_id');
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

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'user_id');
    }

    public function serviceCategories()
    {
        return $this->hasMany(ServiceCategory::class, 'user_id');
    }

    public function emailSubscriptions(): HasMany
    {
        return $this->hasMany(EmailSubscription::class, 'user_id');
    }

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

        // Staff endpoints need to bind trashed customers/services for restore/hard-delete
        if (in_array($childType, [Customer::class, Service::class], true)) {
            $query->withTrashed();
        }

        return $query;
    }
}
