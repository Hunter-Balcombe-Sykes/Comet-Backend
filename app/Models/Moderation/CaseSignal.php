<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Database\Factories\Moderation\CaseSignalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseSignal extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.case_signals';

    public $timestamps = false;     // only created_at exists, no updated_at

    protected $keyType = 'string';

    public $incrementing = false;

    // Mass-assignment posture (SEC-1): explicit allowlist replaces the
    // permissive `$guarded = ['id']`. `id` stays out (DB gen_random_uuid()
    // default, PK). created_at stays out — DB DEFAULT NOW() fills it; no
    // writer (ContentReportService::submit() via forceCreate()) mass-assigns it.
    protected $fillable = [
        'case_id',
        'signal_source',
        'signal_data',
        'reporter_user_id',
        'reporter_email',
        'reporter_ip_hash',
        'reason_code',
        'reason_details',
        'dedup_hash',
    ];

    protected $casts = [
        'signal_data' => 'array',
        'created_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    public function reporterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    protected static function newFactory(): CaseSignalFactory
    {
        return CaseSignalFactory::new();
    }
}
