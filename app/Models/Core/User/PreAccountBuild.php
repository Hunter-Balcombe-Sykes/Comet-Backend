<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\Core\User\PreAccountBuildFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id 1:1 FK to core.users.id (UNIQUE, ON DELETE CASCADE). Not fillable — set via ->user()->associate().
 * @property string $source_type One of 'instagram'|'google_business' (source_type CHECK) — the pairing map key in config('partna.pre_account.*').
 * @property string $source_ref The raw source reference as typed/looked up (handle, place id, etc.).
 * @property string $source_ref_lc Lowercased $source_ref — the dedupe key (pre_account_builds_live_source_unique).
 * @property string $built_via One of VIA_* ('signup'|'staff'|'early_access') (built_via CHECK).
 * @property string|null $built_by_staff_id FK to core.partna_staff.id, ON DELETE SET NULL. NULL for signup-originated builds. Not fillable — set via ->builtByStaff()->associate().
 * @property string $build_state One of STATE_* — text NOT NULL DEFAULT 'pending' with a matching CHECK constraint (supabase/migrations/20260718200000_pre_account_sites.sql).
 * @property string|null $failure_code One of FAILURE_* (e.g. FAILURE_SOURCE_NOT_FOUND, FAILURE_SCRAPE_FAILED) when build_state is 'failed' — not DB-CHECK-enforced, app-level vocabulary only.
 * @property Carbon|null $thin_scrape_at Stamped when the source scrape returned no post timeline. INDEPENDENT of build_state — a thin build stays 'ready' because the site still renders. Not fillable (state column, SEC-4).
 * @property string|null $created_ip_hash sha256(CF-Connecting-IP); NULL for staff-built rows (no visitor IP to hash).
 * @property Carbon|null $expires_at Drives builds:prune-expired; irrelevant once claimed. NULL = never-expire (early-access builds, until staff approval).
 * @property string|null $contact_email Notify address + email-gate value; NULL = first-come claim.
 * @property Carbon|null $invited_at When the claim invite was sent (ClaimNotifier stamps it after queueing the mail). NULL = not yet invited — the idempotency guard.
 * @property bool $auto_invite false = publish the site but DEFER the claim invite for manual review + POST /builds/{build}/invite. Default true = auto-send on publish (unchanged).
 * @property Carbon|null $claimed_at NULL while the build is live (scopeLive); set once the visitor claims the site.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read PartnaStaff|null $builtByStaff
 */
// Permanent origin record for a pre-account (site-first) build. 1:1 with the
// provisional user; survives claim. NOT a ledger of ongoing source interactions —
// post-claim refreshes belong to platform_connections.
class PreAccountBuild extends BaseModel
{
    use HasFactory, HasUuids;

    public const STATE_PENDING = 'pending';

    public const STATE_BUILDING = 'building';

    public const STATE_READY = 'ready';

    public const STATE_FAILED = 'failed';

    public const FAILURE_SOURCE_NOT_FOUND = 'source_not_found';

    public const FAILURE_SCRAPE_FAILED = 'scrape_failed';

    // LIFE-4: stamped by builds:reconcile-stuck when a build sat in
    // pending/building past partna.pre_account.stuck_build_sla_minutes —
    // app-level vocabulary only (failure_code has no DB CHECK constraint).
    public const FAILURE_STUCK_TIMEOUT = 'stuck_timeout';

    public const VIA_SIGNUP = 'signup';

    public const VIA_STAFF = 'staff';

    public const VIA_EARLY_ACCESS = 'early_access';

    protected $table = 'core.pre_account_builds';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id / built_by_staff_id deliberately NOT fillable — set via associate().
    // SEC-4: build_state/claimed_at/failure_code drive the state machine and are
    // also excluded — writers use forceFill()/direct assignment (a silently
    // dropped write here strands a build in the wrong state with zero error).
    // thin_scrape_at is a state column on the same grounds.
    protected $fillable = [
        'source_type', 'source_ref', 'source_ref_lc', 'built_via',
        'created_ip_hash', 'expires_at', 'contact_email', 'auto_invite',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'invited_at' => 'datetime',
        'thin_scrape_at' => 'datetime',
        'auto_invite' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function builtByStaff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'built_by_staff_id');
    }

    /**
     * Was this build made FOR someone, rather than BY them?
     *
     * An outreach build carries a real business's name, photos and hours,
     * scraped before that business has ever heard of Partna — so it must only
     * ever be claimed by an invited address, never first-come.
     *
     * Keyed on WHO CREATED THE ROW, not on `built_via`. `built_via` is derived
     * from the caller (PreAccountBuildService: `$builtVia ?? ($staff ?
     * VIA_STAFF : VIA_SIGNUP)`), and the public unauthenticated build endpoint
     * passes neither — so every row it creates reads `signup`, and anything
     * exempting `signup` would exempt an attacker's scrape of a real business.
     * `built_by_staff_id` cannot be set from a public request.
     *
     * VIA_EARLY_ACCESS is trustworthy here only because the public
     * early-access form no longer builds synchronously (see
     * EarlyAccessService): the sole remaining writer of an early-access build
     * is ApproveEarlyAccessBuildJob, behind staff approval. If that ever
     * changes, this arm must go — an anonymous caller able to set `built_via`
     * would use it to exempt themselves.
     *
     * VIA_STAFF is checked in addition to `built_by_staff_id` (not instead of
     * it) because `built_by_staff_id` is ON DELETE SET NULL: deleting the
     * staff row that created a build silently un-gates it. Both come from the
     * same `$staff ? VIA_STAFF : VIA_SIGNUP` expression at creation time, so
     * `built_via` is exactly as trustworthy and survives the staff row going
     * away.
     */
    public function isOutreach(): bool
    {
        return $this->built_by_staff_id !== null
            || $this->built_via === self::VIA_STAFF
            || $this->built_via === self::VIA_EARLY_ACCESS;
    }

    /**
     * "Dark Until Claimed" — deliberately NARROWER than isOutreach(). An
     * unclaimed build is only safe to make publicly routable before it's
     * claimed if someone with authority actually vetted it: staff-built, or
     * an early-access lead staff has approved.
     *
     * A raw VIA_EARLY_ACCESS row does NOT qualify on its own: requestBuild()
     * gives every early-access build expires_at = NULL at creation, and only
     * ApproveEarlyAccessBuildJob re-stamps a real expiry once staff approve
     * it — so expires_at !== null IS the "staff approved this" signal here.
     * (isOutreach() trusts built_via === VIA_EARLY_ACCESS alone for the claim
     * gate, which is a different, looser question: "does claiming this need
     * an invite" — an unapproved lead still shouldn't be first-come
     * claimable. Visibility is stricter: an unapproved lead's site must not
     * be live on the internet at all yet.)
     *
     * `POST /api/public/signup/build` (self-serve) never sets built_via —
     * PreAccountBuildService derives 'signup' for it — so this is exactly as
     * unforgeable from a public request as isOutreach().
     */
    public function isVisibleWhileUnclaimed(): bool
    {
        return $this->built_by_staff_id !== null
            || ($this->built_via === self::VIA_EARLY_ACCESS && $this->expires_at !== null);
    }

    /** Live = not yet claimed (the partial-unique-index predicate). */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('claimed_at');
    }

    // Namespaced model — Laravel's default factory resolution can't find it
    // without this override (mirrors PartnaStaff::newFactory(), Site::newFactory()).
    protected static function newFactory(): PreAccountBuildFactory
    {
        return PreAccountBuildFactory::new();
    }
}
