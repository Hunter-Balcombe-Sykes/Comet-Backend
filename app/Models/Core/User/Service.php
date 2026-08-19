<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
// Services cutover (2026-08): site.services is DROPPED. This class survives
// ONLY as the in-memory wire shape ServiceResource maps — every instance is
// unsaved (exists = false), hydrated by ManualServiceItems (owner-authored)
// or FreshaServiceItems (connection-sourced). NEVER query it:
// tests/Feature/Architecture/LegacyServiceQuerySurfaceTest.php pins that no
// code in app/ does.
//
// What the columns MEAN now, since they are populated by hand: source NULL =
// owner-authored, 'fresha' = connection-sourced with external_id carrying the
// vendor serviceId; is_manual = the item carries a content.manual_overrides
// row ("sync broken"); is_active = not excluded (manual) / not on the blob's
// hiddenServiceIds (Fresha); deleted_at mirrors content.items.removed_at;
// sort_order is site.section_items.sort_key, with PHP_INT_MAX standing in for
// an unpositioned item (ServiceResource maps that sentinel back to null).
//
// The SoftDeletes trait and the $table binding are retained deliberately: the
// legacy fixtures that still seed site.services live until the DROP unit, and
// they soft-delete through this model. Both go with the table.
class Service extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.services';

    public $incrementing = false;

    protected $keyType = 'string';

    // SEC-1: user_id is server-managed — excluded from mass-assignment. Set by
    // direct property assignment on a pre-authorization skeleton; the User
    // relation that used to set the FK went with the dropped table.
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

    // categories() is GONE (services cutover): its pivot,
    // site.service_category_assignments, is dropped, and every consumer reads
    // the memberships pre-set onto the relation by the hydrators instead —
    // which is what kept a content-item id from ever being queried against
    // that pivot in the first place.
}
