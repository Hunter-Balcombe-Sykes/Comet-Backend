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
 *
 * OV-D adds `type` (error/good/bad_ui/idea — the taxonomy the dashboard
 * feedback picker actually submits) + `area`/`target` (which feature/page/
 * tool). `type` is a separate taxonomy from the legacy `kind` column, not a
 * replacement — see FeedbackService::deriveKind() for how the two reconcile
 * on write. See supabase/migrations/20260711153000_feedback_type_area_target.sql.
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
        'type',
        'area',
        'target',
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
        'target' => 'array',
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
