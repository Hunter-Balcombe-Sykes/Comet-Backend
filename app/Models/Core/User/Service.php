<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property string|null $description
 * @property int $price_cents
 * @property string $currency_code
 * @property int|null $duration_minutes
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_origin
 * @property string|null $source
 * @property bool $is_manual
 * @property string|null $external_id
 * @property-read Collection<int, ServiceCategory> $categories
 */
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

    // SEC-1: user_id is server-managed — excluded from mass-assignment. Set via
    // the ->services() relation's create() (sets the FK directly) or direct
    // property assignment on a pre-authorization skeleton.
    protected $fillable = [
        'title',
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

    /**
     * Every category this service is listed under (multi-category, 2026-07-21).
     * No pivot ordering — the grouped display orders by category sort_order
     * then the service's global sort_order, exactly as before.
     *
     * @return BelongsToMany<ServiceCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'site.service_category_assignments', 'service_id', 'service_category_id')
            ->withTimestamps();
    }
}
