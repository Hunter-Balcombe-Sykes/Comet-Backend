<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 1:1 with site.sites — the workplace card for a professional's public page.
// PK is site_id (FK to site.sites); no separate UUID generated.
// Promoted from site.sites.settings->'workplace' JSONB (FOUND-4).
// A row with a null name is valid (setPreviousWebsite + GoogleBusinessAutoSync may write
// other fields without a name); callers must check name before treating the card as live.
class Workplace extends BaseModel
{
    protected $table = 'site.workplaces';

    // site_id is both PK and FK — not auto-incremented, not UUID-generated.
    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        // PK is site_id — must be fillable so updateOrCreate/firstOrNew can set it
        // when creating a new row. All write paths take site_id from the auth-resolved
        // current site, so mass-assignment of this column is safe.
        'site_id',
        'name',
        'address',
        'address_line1',
        'city',
        'state',
        'postcode',
        'country',
        'latitude',
        'longitude',
        'phone',
        'website',
        'previous_website',
        'category',
        'description',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        // Brand-signal analysis of previous_website (WebsiteStyleAnalyzer
        // output), kept in step with the URL by WorkplaceObserver. System-
        // written only via direct attribute assignment (AnalyzePreviousWebsiteJob)
        // — deliberately NOT in $fillable so no mass-assignment path can set it.
        'previous_website_analysis' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
