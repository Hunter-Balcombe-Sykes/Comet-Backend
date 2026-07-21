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

    /**
     * App-layer mirror of the DB CHECK feedback_status_check
     * (supabase/migrations/20260526210001_create_feedback_table.sql).
     * SQLite tests don't enforce the CHECK, so Rule::in(self::STATUSES) in
     * StaffFeedbackUpdateRequest is the enforcement CI actually exercises —
     * keep the two lists identical.
     */
    public const STATUSES = ['new', 'triaged', 'in_progress', 'shipped', 'wontfix', 'duplicate'];

    protected $table = 'core.feedback';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id is not mass-assignable (nullable but a silent drop would sever
    // the row from its submitter): FeedbackController::store()'s policy-check
    // skeleton sets it via direct property assignment; FeedbackService::submit()
    // sets it via ->user()->associate(). status/internal_notes/tags all have
    // DB defaults and are written only via direct assignment
    // (FeedbackService::updateStatus() for status; no writer mass-assigns the
    // other two), so excluding them here is inert, not a functional change.
    protected $fillable = [
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
