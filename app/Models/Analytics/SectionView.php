<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phase 5 analytics — per-session per-section visibility events fired by Partna-Hydrogen's
// IntersectionObserver. Each session+section pair is deduped at write time (5min sliding
// window) so scroll-back doesn't inflate counts.
//
// Mirrors analytics.link_clicks shape — same UTM/identity fields plus an optional FK to
// site.blocks (for sections that correspond to a Block) AND a required section_key (for
// non-Block sections like header/footer/bio).
class SectionView extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'analytics.section_views';

    public $incrementing = false;

    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'section_key',
        'block_id',
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
        'device_type',
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

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id');
    }
}
