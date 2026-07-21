<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A bookable service offered by a professional. Stores pricing, duration,
// and display metadata. Provenance (2026-07-21): source NULL = owner-authored
// (manual); source='fresha' = projected from the Fresha scrape, identified by
// external_id (the Fresha serviceId). An owner edit on a projected row flips
// is_manual ("sync broken") — the re-scrape then never overwrites it, and the
// revert/resync endpoints re-project it from the stored raw scrape. Deleting a
// projected row records suppression via soft delete + deleted_origin='user'.
class Service extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.services';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'title',
        'category_id',
        'description',
        'price_cents',
        'currency_code',
        'duration_minutes',
        'is_active',
        'sort_order',
        'source',
        'is_manual',
        'external_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
        'duration_minutes' => 'integer',
        'is_manual' => 'boolean',
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
