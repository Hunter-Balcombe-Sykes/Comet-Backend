<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One landed piece of a pre-account build's fan-out — the setup progress
 * ledger (2026-09-02). Written only through BuildProgress::note(); read by
 * the public build poll and the handle progress endpoint. Never fillable
 * from a request: the producers are the jobs, and the label is theirs.
 *
 * @property string $id
 * @property string $build_id
 * @property string $stage One of STAGES (DB CHECK).
 * @property string $status One of STATUSES (DB CHECK).
 * @property string $label The sentence the UI shows, verbatim from the producer.
 * @property array<string, mixed> $payload Optional: platform slugs, counts, a few thumbnail URLs.
 * @property Carbon $created_at
 */
class PreAccountBuildEvent extends BaseModel
{
    use HasUuids;

    public const STAGE_IDENTITY = 'identity';

    public const STAGE_MEDIA = 'media';

    public const STAGE_WORKPLACE = 'workplace';

    public const STAGE_PLATFORMS = 'platforms';

    public const STAGE_LISTING = 'listing';

    public const STAGE_MENU = 'menu';

    public const STAGE_WEBSITE = 'website';

    /** Since migration 20260902050000 — the store and its products as they sync. */
    public const STAGE_SHOP = 'shop';

    public const STAGE_READY = 'ready';

    public const STAGE_FAILED = 'failed';

    public const STAGES = [
        self::STAGE_IDENTITY, self::STAGE_MEDIA, self::STAGE_WORKPLACE, self::STAGE_PLATFORMS,
        self::STAGE_LISTING, self::STAGE_MENU, self::STAGE_WEBSITE, self::STAGE_SHOP, self::STAGE_READY, self::STAGE_FAILED,
    ];

    public const STATUS_STARTED = 'started';

    public const STATUS_LANDED = 'landed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_STARTED, self::STATUS_LANDED, self::STATUS_SKIPPED, self::STATUS_FAILED];

    protected $table = 'core.pre_account_build_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<PreAccountBuild, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(PreAccountBuild::class, 'build_id');
    }
}
