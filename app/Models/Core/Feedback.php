<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * User-submitted feedback (bug/idea/praise/question/other).
 *
 * Authenticated dashboard submissions only at first; schema reserves space
 * for anonymous + public-site sources in a future iteration. See
 * docs/superpowers/plans/2026-05-25-feedback-system.md.
 */
class Feedback extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'core.feedback';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'reply_email',
        'kind',
        'severity',
        'message',
        'page_url',
        'user_agent',
        'viewport',
        'app_version',
        'request_id',
        'status',
        'internal_notes',
        'tags',
        'source',
        'ip_hash',
    ];

    protected $casts = [
        'internal_notes' => 'array',
        'tags' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
