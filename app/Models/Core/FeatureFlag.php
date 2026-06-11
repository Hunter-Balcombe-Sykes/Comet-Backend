<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeatureFlag extends BaseModel
{
    use SoftDeletes;

    protected $table = 'core.feature_flags';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'description', 'default_enabled', 'rollout_percent'];

    protected function casts(): array
    {
        return [
            'default_enabled' => 'boolean',
            'rollout_percent' => 'integer',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(FeatureFlagOverride::class, 'flag_key', 'key');
    }
}
