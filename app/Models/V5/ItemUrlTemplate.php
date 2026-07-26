<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemUrlTemplate extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.item_url_templates';

    protected $fillable = [
        'template', 'platform_definition_id', 'item_type',
        'is_platform_syncable', 'platform_identifier', 'source_method',
    ];

    protected function casts(): array
    {
        return ['is_platform_syncable' => 'boolean'];
    }

    public function platformDefinition(): BelongsTo
    {
        return $this->belongsTo(PlatformDefinition::class, 'platform_definition_id');
    }
}
