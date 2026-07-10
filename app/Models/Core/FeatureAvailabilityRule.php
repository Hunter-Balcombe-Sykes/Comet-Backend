<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\Segments\UserSegment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// OV-A: one staff-managed availability rule for a feature/integration key.
// segment_id NULL = the global row for that key. Read side lives in
// App\Services\FeatureAvailability\FeatureAvailability (absence = available;
// segment rows beat global; disabled wins across segments).
class FeatureAvailabilityRule extends BaseModel
{
    use HasUuids;

    public const MODE_ENABLED = 'enabled';

    public const MODE_DISABLED = 'disabled';

    protected $table = 'core.feature_availability';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'feature_key',
        'mode',
        'segment_id',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function segment(): BelongsTo
    {
        return $this->belongsTo(UserSegment::class, 'segment_id');
    }
}
