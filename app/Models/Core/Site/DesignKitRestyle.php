<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One applied "Restyle from brand" (plan §13, migration 20260727150000).
 *
 * The row IS the undo: `snapshot` holds every design_kits var column as it
 * stood the moment the restyle applied — a typed snapshot (C1), not a diff —
 * so undo is a plain restore with no arithmetic. `undone_at` makes the
 * history append-only; nothing here is ever deleted by the flow.
 *
 * @property string $id
 * @property string $site_id FK → site.sites.id.
 * @property array<string, mixed> $snapshot design_kits vars before the apply.
 * @property Carbon $applied_at
 * @property Carbon|null $undone_at
 * @property-read Site|null $site
 */
class DesignKitRestyle extends BaseModel
{
    use HasUuids;

    protected $table = 'site.design_kit_restyles';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    // site_id assigned directly, never mass-assigned — the policy resolves
    // ownership off the raw attribute, same shape as Page/Section.
    protected $fillable = [
        'snapshot',
        'applied_at',
        'undone_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'applied_at' => 'datetime',
        'undone_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
