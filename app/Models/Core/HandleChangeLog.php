<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit log of every handle rename / reclaim. Used for fraud
// investigation, impersonation disputes, and trademark complaints. DB trigger
// blocks UPDATE/DELETE — never mutate from PHP.
class HandleChangeLog extends BaseModel
{
    use HasUuids;

    public const REASON_RENAME = 'rename';

    public const REASON_RECLAIM = 'reclaim';

    public const REASON_STAFF_RENAME = 'staff_rename';

    public const REASON_SYSTEM = 'system';

    protected $table = 'audit.handle_change_log';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'old_handle',
        'new_handle',
        'reason',
        'actor_id',
        'ip_address',
        'user_agent',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
