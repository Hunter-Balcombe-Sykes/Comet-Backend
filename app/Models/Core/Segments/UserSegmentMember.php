<?php

namespace App\Models\Core\Segments;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// OV-A: manual segment membership — additive on top of the segment's dynamic filters.
class UserSegmentMember extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_segment_members';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null; // membership rows are insert-only (created_at column only)

    protected $fillable = [
        'segment_id',
        'user_id',
        'added_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function segment(): BelongsTo
    {
        return $this->belongsTo(UserSegment::class, 'segment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
