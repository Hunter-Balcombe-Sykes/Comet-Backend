<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

// V2: Admin-level email delivery policy per notification category. Controls the mode (e.g., immediate, digest, off) for a professional's category.
class NotificationEmailPolicy extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications.notification_email_policies';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'category_key',
        'mode',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
