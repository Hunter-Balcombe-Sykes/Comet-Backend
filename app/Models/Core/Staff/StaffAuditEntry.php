<?php

namespace App\Models\Core\Staff;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// OPS-2: Append-only audit log of every staff write. One row inserted by
// App\Http\Middleware\Logging\RecordStaffAuditEntry after a /staff/* write
// response is sent. Body capture is deliberately omitted from the default
// path; payload_summary holds route bindings only.
class StaffAuditEntry extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'audit.staff_audit_log';

    public $incrementing = false;

    protected $keyType = 'string';

    // No updated_at — this table is append-only. Laravel will still set
    // created_at automatically because UPDATED_AT is the only constant we
    // override to null.
    const UPDATED_AT = null;

    protected $fillable = [
        'staff_id',
        'staff_email_snapshot',
        'impersonator_staff_id',
        'impersonator_email_snapshot',
        'user_id',
        'professional_handle_snapshot',
        'route',
        'http_method',
        'status_code',
        'payload_summary',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'staff_id');
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'impersonator_staff_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
