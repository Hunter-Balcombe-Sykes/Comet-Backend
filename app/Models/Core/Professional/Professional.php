<?php

namespace App\Models\Core\Professional;

use App\Enums\AccountType;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\BaseModel;
use App\Models\Billing\Subscription;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
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

// V2: Central identity model. Both brands and affiliates are professionals distinguished by professional_type. Owns site, services, customers, integrations.
class Professional extends BaseModel
{
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'core.professionals';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'auth_user_id',
        'stripe_connect_account_id',
        'stripe_billing_customer_id',
        'stripe_payment_method_id',
        'stripe_commission_funding_mode',
        'deletion_token_hash',
    ];

    protected $fillable = [
        'handle',
        'display_name',
        'bio',
        'about',
        'country_code',
        'timezone',
        'professional_type',
        'account_type',
        'status',
        'onboarding_step',
        'phone',
        'primary_email',
        'first_name',
        'last_name',

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

        // Stripe Connect + commission funding.
        'stripe_connect_account_id',
        'stripe_connect_status',
        'stripe_payment_method_id',
        'stripe_payment_method_brand',
        'stripe_payment_method_last4',
        'stripe_commission_funding_mode',
        'payout_method',

        // Platform-side SaaS subscription billing — separate identity from Connect.
        'stripe_billing_customer_id',

        // Phase 4 — multi-PM (BECS + card) with preferred selection. Legacy stripe_payment_method_*
        // columns above continue to mirror the current preferred PM as a snapshot for back-compat.
        'stripe_card_payment_method_id',
        'stripe_card_brand',
        'stripe_card_last4',
        'stripe_becs_payment_method_id',
        'stripe_becs_bsb',
        'stripe_becs_last4',
        'preferred_payout_method',

        // Account deletion lifecycle
        'deletion_token_hash',
        'deletion_requested_at',
        'deletion_confirmed_at',
        'deletion_previous_status',

        // Staff-only — surfaced through ProfessionalStaffResource. Never expose through
        // ProfessionalResource (self-service /me).
        'admin_notes',
    ];

    protected $casts = [
        'onboarding_step' => 'integer',
        'about' => 'array',
        'account_type' => AccountType::class,
        // Observer-maintained; not in $fillable so a bulk save() can't desync it
        // from the brand_partner_links table.
        'has_historical_partner_links' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'deletion_requested_at' => 'datetime',
        'deletion_confirmed_at' => 'datetime',
    ];

    /** Route mail notifications to the professional's primary email address. */
    public function routeNotificationForMail(): string
    {
        return $this->primary_email;
    }

    public function isInfluencer(): bool
    {
        return mb_strtolower(trim((string) ($this->professional_type ?? ''))) === 'influencer';
    }

    // Account-type predicates. Read account_type when it has been set (dual-write
    // window) and fall back to professional_type only as a safety net for code
    // paths that haven't been migrated yet. Source of truth: account_type.
    public function isBrand(): bool
    {
        if ($this->account_type instanceof AccountType) {
            return $this->account_type === AccountType::Brand;
        }

        return mb_strtolower(trim((string) ($this->professional_type ?? ''))) === 'brand';
    }

    public function isPartner(): bool
    {
        return $this->account_type instanceof AccountType
            && $this->account_type === AccountType::Partner;
    }

    public function isIndividual(): bool
    {
        return $this->account_type instanceof AccountType
            && $this->account_type === AccountType::Individual;
    }

    public function isProfessional(): bool
    {
        return mb_strtolower(trim((string) ($this->professional_type ?? ''))) === 'professional';
    }

    // Account is in the post-confirm grace period: read-only HTTP, write-blocked
    // policies. Canonical predicate — middleware and Policies both consult this
    // so the literal status string lives in exactly one place.
    public function isPendingDeletion(): bool
    {
        return mb_strtolower(trim((string) ($this->status ?? ''))) === 'pending_deletion';
    }

    public function brandProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class, 'professional_id');
    }

    public function site(): HasOne
    {
        return $this->hasOne(Site::class, 'professional_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'professional_id');
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
        return $this->hasMany(Customer::class, 'professional_id')
            ->orderByDesc('created_at');
    }

    public function siteVisits(): HasMany
    {
        return $this->hasMany(SiteVisit::class, 'professional_id');
    }

    public function linkClicks(): HasMany
    {
        return $this->hasMany(LinkClick::class, 'professional_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'professional_id');
    }

    public function serviceCategories()
    {
        return $this->hasMany(ServiceCategory::class);
    }

    public function emailSubscriptions(): HasMany
    {
        return $this->hasMany(EmailSubscription::class, 'professional_id');
    }

    public function brandAffiliateInvites(): HasMany
    {
        return $this->hasMany(BrandAffiliateInvite::class, 'brand_professional_id')
            ->orderByDesc('created_at');
    }

    /**
     * All brand connections where this professional is the affiliate.
     * Empty for brand accounts (brands connect TO affiliates, not the reverse).
     */
    public function brandPartnerLinks(): HasMany
    {
        return $this->hasMany(BrandPartnerLink::class, 'affiliate_professional_id');
    }

    /**
     * Same as brandPartnerLinks() but includes soft-deleted rows. Use this for
     * ex-partner derivation and historical-link queries; default queries
     * (auto-excluded via the SoftDeletes trait) are still the right hot path.
     */
    public function brandPartnerLinksAll(): HasMany
    {
        return $this->hasMany(BrandPartnerLink::class, 'affiliate_professional_id')->withTrashed();
    }

    /**
     * The affiliate's primary brand connection (slot 0). V2 uses a single-brand
     * model, so this is effectively "the brand" for an affiliate, or null for
     * an affiliate that hasn't connected yet / a brand account.
     */
    public function primaryBrandPartnerLink(): HasOne
    {
        return $this->hasOne(BrandPartnerLink::class, 'affiliate_professional_id')
            ->where('slot', 0);
    }

    /**
     * Return the industries that drive this professional's experience.
     *
     * - Brand: its own BrandProfile.industries (primary at index 0).
     * - Affiliate (professional/influencer): the primary (slot=0) connected
     *   brand's industries. If multi-brand lands later, the "union across
     *   brands" decision is deferred — see docs/brand-industries.md §7.
     *
     * Always returns a clean array of string slugs; empty strings and
     * non-strings are filtered defensively (legacy free-form data could
     * contain either).
     *
     * @return array<int, string>
     */
    public function effectiveIndustries(): array
    {
        $industries = $this->isBrand()
            ? ($this->brandProfile?->industries ?? [])
            : ($this->primaryBrandPartnerLink?->brandProfessional?->brandProfile?->industries ?? []);

        if (! is_array($industries)) {
            return [];
        }

        return array_values(array_filter(
            $industries,
            static fn ($value) => is_string($value) && $value !== ''
        ));
    }

    /**
     * First-is-primary convention: the first industry in the list is the
     * primary. Null when no industries are set.
     */
    public function primaryIndustry(): ?string
    {
        return $this->effectiveIndustries()[0] ?? null;
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'professional_id');
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(ProfessionalIntegration::class, 'professional_id');
    }

    public function squareIntegration(): HasOne
    {
        return $this->hasOne(ProfessionalIntegration::class, 'professional_id')
            ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE);
    }

    public function freshaIntegration(): HasOne
    {
        return $this->hasOne(ProfessionalIntegration::class, 'professional_id')
            ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA);
    }

    public function shopifyIntegration(): HasOne
    {
        return $this->hasOne(ProfessionalIntegration::class, 'professional_id')
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY);
    }

    public function integrationForProvider(string $provider): ?ProfessionalIntegration
    {
        $provider = mb_strtolower(trim($provider));

        if ($provider === '') {
            return null;
        }

        if ($this->relationLoaded('integrations')) {
            return $this->integrations->firstWhere('provider', $provider);
        }

        return $this->integrations()->where('provider', $provider)->first();
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
