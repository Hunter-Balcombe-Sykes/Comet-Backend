<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FeatureFlagOverride extends BaseModel
{
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

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if (empty($row->id)) {
                $row->id = (string) Str::uuid();
            }
        });
    }

    public function flag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class, 'flag_key', 'key');
    }
}
