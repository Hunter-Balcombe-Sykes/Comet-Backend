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
 * @property array{v?: int, url?: string, finalUrl?: string|null, ok?: bool, mode?: string, failure?: string|null, accent?: array|null, analyzedAt?: string, signals?: array<string, array>, logo?: array{candidates?: list<array>, grab?: list<array{slot: string, kind: string, ref: string, outcome: string}>}, notes?: list<string>}|null $previous_website_analysis See the legacy-shape note below the docblock.
 * @property array<string, list<array{open: string, close: string}>>|null $opening_hours Per-day hours keyed by 3-letter day (mon..sun) plus an 'exceptions' list, e.g. {"mon":[{"open":"0900","close":"1700"}],...,"exceptions":[]}. Derived from Google regularOpeningHours.periods on sync, or set manually.
 * @property string|null $contact_email Manual-only — Google Places never returns an email.
 * @property array<string, array{source: string, at: string}> $field_sources Per-field provenance, e.g. {"name":{"source":"google-business","at":"<iso>"}}. System-written by IdentitySync + UserWorkplaceController — not in $fillable.
 * @property Carbon|null $created_at DB column is nullable with no default (unlike most tables); Eloquent still populates it on every model-created row.
 * @property Carbon|null $updated_at Same caveat as created_at.
 * @property-read Site|null $site
 */
// previous_website_analysis — why EVERY key in its shape is optional:
//   The keys are what WebsiteStyleAnalyzer v2 writes (AnalyzePreviousWebsiteJob, including its
//   exception-fallback doc; kept in step by WorkplaceObserver). But this is a persisted jsonb
//   column and no migration ever backfilled or cleared it, so rows written by the v1 analyzer —
//   a different shape: 'tiers'/'logoCandidates', no 'mode'/'notes'/'finalUrl' — are still readable.
//   PreviousWebsiteFactor gates on ($analysis['v'] ?? null) !== WebsiteStyleAnalyzer::VERSION
//   precisely to reject those legacy blobs. Typing the keys as required would make that guard look
//   dead and invite its removal. logo.grab is added post-hoc, only when LogoAutoGrabber fills a slot.
//
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
        // Brand-signal analysis of previous_website (WebsiteStyleAnalyzer
        // output), kept in step with the URL by WorkplaceObserver. System-
        // written only via direct attribute assignment (AnalyzePreviousWebsiteJob)
        // — deliberately NOT in $fillable so no mass-assignment path can set it.
        'previous_website_analysis' => 'array',
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
