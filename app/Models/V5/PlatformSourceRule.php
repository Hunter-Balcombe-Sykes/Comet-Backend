<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PlatformSourceRule extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.platform_source_rules';

    protected $fillable = [
        'source_config_id', 'platform_definition_id', 'platform_category_id',
        'rule_name', 'default_value', 'is_enabled', 'is_applicable',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_applicable' => 'boolean',
        ];
    }
}
