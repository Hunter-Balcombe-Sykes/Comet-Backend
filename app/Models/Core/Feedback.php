<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 *
 * @property string $id
 * @property string|null $user_id
 * @property string|null $reply_email
 * @property string $kind
 * @property string|null $severity
 * @property string $message
 * @property string|null $page_url
 * @property string|null $user_agent
 * @property string|null $viewport
 * @property string|null $app_version
 * @property string|null $request_id
 * @property string $status
 * @property array<int, mixed> $internal_notes
 * @property array<int, string> $tags
 * @property string $source
 * @property string|null $ip_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $type
 * @property string|null $area
 * @property array<string, mixed>|null $target
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
