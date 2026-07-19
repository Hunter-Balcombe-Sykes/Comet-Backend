<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\Core\User\PreAccountBuildFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public const VIA_SIGNUP = 'signup';

    public const VIA_STAFF = 'staff';

    protected $table = 'core.pre_account_builds';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id / built_by_staff_id deliberately NOT fillable — set via associate().
    protected $fillable = [
        'source_type', 'source_ref', 'source_ref_lc', 'built_via',
        'build_state', 'failure_code', 'created_ip_hash', 'expires_at', 'claimed_at',
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
