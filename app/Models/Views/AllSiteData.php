<?php

namespace App\Models\Views;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// Read-only database view that denormalizes a site with its blocks and
// settings into a single row for efficient dashboard queries. After the
// skeleton-system cleanup the view exposes `skeleton_id` (TEXT enum) instead
// of the old theme join columns.
class AllSiteData extends BaseModel
{
    use HasFactory;

    protected $table = 'site.all_site_data';

    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    // view = read-only (keep it safe)
    protected $guarded = [];

    protected $casts = [
        'site_settings' => 'array',
        'blocks' => 'array',
        'is_published' => 'boolean',
        'site_created_at' => 'datetime',
        'site_updated_at' => 'datetime',
    ];
}
