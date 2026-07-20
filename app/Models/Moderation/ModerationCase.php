<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Database\Factories\Moderation\CaseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $case_type One of 'content_report'|'csam_match'|'trusted_flagger'|'manual'|'esafety_takedown' (cases_case_type_check).
 * @property string $reportable_type One of 'Site'|'SiteMedia'|'User'|'Block'|'Service' (cases_reportable_type_check).
 * @property string $reportable_id Polymorphic target id (see reportable_type for the class).
 * @property string|null $reportable_owner_user_id
 * @property int $severity 1-5 (cases_severity_check).
 * @property string $status One of 'open'|'triaged'|'under_review'|'resolved'|'auto_actioned' (cases_status_check).
 * @property int $signal_count Count of moderation.case_signals rows folded into this case; >= 1 (cases_signal_count_check).
 * @property bool $auto_actioned
 * @property int $priority 1-10, lower sorts first (cases_priority_check).
 * @property Carbon|null $sla_due_at
 * @property Carbon|null $triaged_at
 * @property string|null $triaged_by_staff_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $owner
 * @property-read PartnaStaff|null $triagedBy
 * @property-read Collection<int, CaseSignal> $signals
 * @property-read Collection<int, Evidence> $evidence
 * @property-read Collection<int, Decision> $decisions
 */
class ModerationCase extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.cases';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'severity' => 'integer',
        'signal_count' => 'integer',
        'priority' => 'integer',
        'auto_actioned' => 'boolean',
        'sla_due_at' => 'datetime',
        'triaged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reportable_owner_user_id');
    }

    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'triaged_by_staff_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(CaseSignal::class, 'case_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class, 'case_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'case_id');
    }

    protected static function newFactory(): CaseFactory
    {
        return CaseFactory::new();
    }
}
