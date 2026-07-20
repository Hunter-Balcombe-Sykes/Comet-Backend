<?php

namespace App\Models\Views;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

// Read-only database view that denormalizes a site with its blocks and
// settings into a single row for efficient dashboard queries. The view exposes
// `architecture_id` (TEXT enum) instead of the old theme join columns.
//
// Column list mirrors the view's SELECT (supabase/migrations/
// 20260705120000_drop_dead_profile_features.sql, renamed by
// 20260710230000_rename_skeleton_id_to_architecture_id.sql) — update this
// docblock alongside any migration that changes the view's shape.
/**
 * @property string $site_id
 * @property string $user_id
 * @property string|null $subdomain
 * @property bool $is_published
 * @property string|null $architecture_id
 * @property array<string, mixed> $site_settings
 * @property Carbon|null $site_created_at
 * @property Carbon|null $site_updated_at
 * @property string|null $handle
 * @property string|null $display_name
 * @property string|null $location_street_address
 * @property string|null $location_city
 * @property string|null $location_state
 * @property string|null $location_postcode
 * @property string|null $location_country
 * @property array<int, array<string, mixed>> $blocks
 * @property string|null $account_type
 */
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
