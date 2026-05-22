<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use App\Models\Core\Professional\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Tracks per-professional read and dismissed state for a notification. One receipt per professional per notification.
class NotificationReceipt extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications.notification_receipts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'notification_id',
        'professional_id',
        'read_at',
        'dismissed_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
