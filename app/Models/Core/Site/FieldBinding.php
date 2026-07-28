<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One (site, field, source) binding row (plan §14, migration 20260728150000).
 *
 * The row answers "may THIS source write THIS field, and with what standing?"
 * — priority orders sources per field (lower wins; 0 is manual's reserved
 * seat and doubles as the field-side lock), `mode` carries the IdentitySync
 * law's two verbs (overwrite vs fill_blank), and `is_enabled` is the
 * platform-side gate. Sparse on purpose: no row means no write.
 *
 * @property string $id
 * @property string $site_id FK → site.sites.id.
 * @property string $field Workplace identity field name.
 * @property string $source_key 'manual' or an ingest source key.
 * @property int $priority Lower wins; 0 = manual (CHECK-enforced).
 * @property string $mode overwrite|fill_blank
 * @property bool $is_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site|null $site
 */
class FieldBinding extends BaseModel
{
    use HasUuids;

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'site.field_bindings';

    public $incrementing = false;

    protected $keyType = 'string';

    // site_id assigned directly, never mass-assigned — ownership resolves off
    // the raw attribute, same shape as Page/Section/DesignKitRestyle.
    protected $fillable = [
        'field',
        'source_key',
        'priority',
        'mode',
        'is_enabled',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
