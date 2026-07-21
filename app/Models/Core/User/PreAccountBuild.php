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
 * @property string $built_via One of VIA_* ('signup'|'staff') (built_via CHECK).
 * @property string|null $built_by_staff_id FK to core.partna_staff.id, ON DELETE SET NULL. NULL for signup-originated builds. Not fillable — set via ->builtByStaff()->associate().
 * @property string $build_state One of STATE_* — text NOT NULL DEFAULT 'pending' with a matching CHECK constraint (supabase/migrations/20260718200000_pre_account_sites.sql).
 * @property string|null $failure_code One of FAILURE_* (e.g. FAILURE_SOURCE_NOT_FOUND, FAILURE_SCRAPE_FAILED) when build_state is 'failed' — not DB-CHECK-enforced, app-level vocabulary only.
 * @property string|null $created_ip_hash sha256(CF-Connecting-IP); NULL for staff-built rows (no visitor IP to hash).
 * @property Carbon|null $expires_at Drives builds:prune-expired; irrelevant once claimed. NULL = never-expire (early-access builds, until staff approval).
 * @property string|null $contact_email Notify address + email-gate value; NULL = first-come claim.
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
    protected $fillable = [
        'source_type', 'source_ref', 'source_ref_lc', 'built_via',
        'created_ip_hash', 'expires_at', 'contact_email',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
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
