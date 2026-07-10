<?php

namespace App\Models\Core\Segments;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

// OV-A: staff-defined user segment — dynamic JSONB filter definition plus a
// manual member list. Resolved to a live user-id set by SegmentResolver.
// filters shape (all optional, AND-combined): account_type, sector[],
// created_from/created_to, has_integration (true|platform), early_access,
// include_manual_members (default true).
class UserSegment extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_segments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'description',
        'filters',
        'created_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(UserSegmentMember::class, 'segment_id');
    }
}
