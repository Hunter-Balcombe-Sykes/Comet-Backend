<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Tracks individual page visits to a professional's public site. Captures session, visitor, UTM, device, and geo data for traffic analytics.
class SiteVisit extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'analytics.site_visits';

    public $incrementing = false;

    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'session_id',
        'visitor_id',
        'ip_hash',
        'user_agent',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'country_code',
        'region_code',
        'city',
        'device_type',
        // #PARITY-1: added by the 20260707020000 lat/lon migration and wired
        // into PostgresEventWriter::visitRow() (99839d78), but never added
        // here — the exact foot-gun AnalyticsModelFillableTest now guards
        // generatively. user_id/site_id/created_at stay guarded (see that
        // test's GUARDED constant).
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
