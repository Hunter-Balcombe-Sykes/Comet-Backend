<?php

namespace App\Models\Core\Site;

use App\Enums\EnquiryStatus;
use App\Models\BaseModel;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A visitor-submitted enquiry from a site's contact section block. read_at=null means unread.
class Enquiry extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.enquiries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'site_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'ip_hash',
        'user_agent',
        'read_at',
        'email_sent_at',
        'status',
        'customer_id',
        'notification_id',
        'replied_at',
        'archived_at',
        'spam_at',
        'redacted_at',
    ];

    // Submitter PII + request telemetry hidden from default model serialization.
    // UserEnquiryController surfaces these via EnquiryResource (direct attribute
    // access, unaffected by $hidden); naked toArray() in jobs/logs/broadcasts is now safe.
    protected $hidden = [
        'name',
        'email',
        'phone',
        'message',
        'ip_hash',
        'user_agent',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'email_sent_at' => 'datetime',
        'status' => EnquiryStatus::class,
        'replied_at' => 'datetime',
        'archived_at' => 'datetime',
        'spam_at' => 'datetime',
        'redacted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }
}
