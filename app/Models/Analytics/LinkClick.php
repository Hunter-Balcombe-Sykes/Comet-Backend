<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Records clicks on link blocks within a site.
class LinkClick extends BaseModel
{
    use HasUuids;

    protected $table = 'analytics.link_clicks';

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
        return $this->belongsTo(Block::class, 'link_block_id');
    }
}
