<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

// V2: User-facing toggle for enabling or disabling email notifications per category.
// Works alongside the notifications.notification_email_policies table, which has no
// Eloquent model — it is reached through the query builder only (retired 2026-09-04).
class NotificationEmailPreference extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications.notification_email_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'category_key',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
