<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserPlatform extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'v5.user_platforms';

    protected $fillable = [
        'user_id', 'platform_definition_id', 'identifier_value',
        'identifier_name_type', 'is_enabled', 'refresh_interval', 'source_method',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function platformDefinition(): BelongsTo
    {
        return $this->belongsTo(PlatformDefinition::class, 'platform_definition_id');
    }
}
