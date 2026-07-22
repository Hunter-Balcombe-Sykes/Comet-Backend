<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\Moderation\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends BaseModel
{
    use HasFactory;

    protected $table = 'audit.moderation_events';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    // Mass-assignment posture (SEC-1): explicit allowlist replaces the
    // permissive `$guarded = ['id']`. `id` stays out (DB gen_random_uuid()
    // default, PK) — ModerationAuditService::recordStaffAction()/
    // recordSystemAction() call AuditEvent::create() (not forceCreate(), so
    // this guard is live) and pass an explicit 'id' key, but it's silently
    // dropped same as under the old $guarded=['id']; the DB default fills it.
    // created_at stays out too — DB DEFAULT NOW() fills it, no writer mass-assigns it.
    protected $fillable = [
        'actor_kind',
        'actor_staff_id',
        'action',
        'target_type',
        'target_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'actor_staff_id');
    }

    protected static function newFactory(): AuditEventFactory
    {
        return AuditEventFactory::new();
    }
}
