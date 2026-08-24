<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $source
 */
// Services cutover (2026-08): site.service_categories is DROPPED. Like
// Service, this class survives ONLY as the in-memory shape
// ServiceCategoryResource maps — unsaved instances built by the hydrators from
// content.collections rows (id + title are the only fields they set). NEVER
// query it: LegacyServiceQuerySurfaceTest pins that no code in app/ does. The
// services() relation and the ordering global scope are gone with the pivot
// and the table they addressed; $table and SoftDeletes stay only for the
// legacy fixtures that live until the DROP unit.
class ServiceCategory extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.service_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    // SEC-1: user_id is server-managed — excluded from mass-assignment. Set by
    // direct property assignment on a pre-authorization skeleton; the User
    // relation that used to set the FK went with the dropped table.
    protected $fillable = [
        'title',
        'sort_order',
        // 'fresha' = auto-created from a Fresha category label during
        // projection; NULL = owner-authored.
        'source',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
