<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A bookable service offered by a professional. Stores pricing, duration, and display metadata.
class Service extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.services';

    public $incrementing = false;

    protected $keyType = 'string';

    // SEC-1: user_id is server-managed — excluded from mass-assignment. Set via
    // the ->services() relation's create() (sets the FK directly) or direct
    // property assignment on a pre-authorization skeleton.
    protected $fillable = [
        'title',
        'category_id',
        'description',
        'price_cents',
        'currency_code',
        'duration_minutes',
        'is_active',
        'sort_order',

    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
        'duration_minutes' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }
}
