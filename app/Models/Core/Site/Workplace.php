<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $site_id PK + FK → site.sites.id (ON DELETE CASCADE); not UUID-generated.
 * @property string|null $name NULL is a valid state — see class comment.
 * @property string|null $address_line1
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property string|null $country
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $previous_website
 * @property string|null $category
 * @property string|null $description
 * @property array<string, list<array{open: string, close: string}>>|null $opening_hours Per-day hours keyed by 3-letter day (mon..sun) plus an 'exceptions' list, e.g. {"mon":[{"open":"0900","close":"1700"}],...,"exceptions":[]}. Derived from Google regularOpeningHours.periods on sync, or set manually.
 * @property string|null $contact_email Manual-only — Google Places never returns an email.
 * @property array<string, array{source: string, at: string}>|null $field_sources Per-field provenance (NULL until first written), e.g. {"name":{"source":"google-business","at":"<iso>"}}. System-written by IdentitySync + UserWorkplaceController — not in $fillable.
 * @property Carbon|null $created_at DB column is nullable with no default (unlike most tables); Eloquent still populates it on every model-created row.
 * @property Carbon|null $updated_at Same caveat as created_at.
 * @property-read Site|null $site
 */
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
        // Identity fields the Brand/Workplace Info page owns and the Google
        // precedence sync fills. opening_hours is structured per-day JSON.
        'opening_hours',
        'contact_email',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        // Structured per-day opening hours: {"mon":[{"open":"0900","close":"1700"}], ...}.
        'opening_hours' => 'array',
        // Per-field provenance {"<field>":{"source":"google-business|manual","at":"<iso>"}}.
        // System-written by IdentitySync + UserWorkplaceController — NOT in $fillable.
        'field_sources' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
