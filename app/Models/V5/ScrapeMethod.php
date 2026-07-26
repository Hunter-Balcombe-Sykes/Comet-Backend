<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ScrapeMethod extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.scrape_methods';

    protected $fillable = [
        'name', 'base_template', 'base_config', 'platform_overrides',
    ];

    protected function casts(): array
    {
        return [
            'base_config' => 'json',
            'platform_overrides' => 'json',
        ];
    }
}
