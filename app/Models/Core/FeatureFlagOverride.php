<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureFlagOverride extends BaseModel
{
    use HasUuids;

    protected $table = 'core.feature_flag_overrides';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'flag_key', 'user_id', 'enabled',
        'reason', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function flag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class, 'flag_key', 'key');
    }
}
