<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\ActionLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLogEntry extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.action_log';

    protected $keyType = 'string';

    public $incrementing = false;

    // Mass-assignment posture (SEC-1): explicit allowlist replaces the
    // permissive `$guarded = ['id']`. `id` stays out (DB gen_random_uuid()
    // default, PK). created_at/updated_at stay out — Eloquent-managed
    // ($timestamps default true); HasActionLogLifecycle's markDispatched()/
    // markCompleted() only ever touch status/dispatched_at/attempts/completed_at.
    protected $fillable = [
        'decision_id',
        'action_type',
        'action_target',
        'job_uuid',
        'status',
        'attempts',
        'failure_reason',
        'dispatched_at',
        'completed_at',
    ];

    protected $casts = [
        'action_target' => 'array',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'decision_id');
    }

    protected static function newFactory(): ActionLogEntryFactory
    {
        return ActionLogEntryFactory::new();
    }
}
