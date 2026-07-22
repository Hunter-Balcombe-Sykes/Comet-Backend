<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $source
 */
// V2: Sortable grouping for a professional's services. Auto-ordered by sort_order then created_at via a global scope.
class ServiceCategory extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.service_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    // SEC-1: user_id is server-managed — excluded from mass-assignment. Set via
    // the ->serviceCategories() relation's create() (sets the FK directly) or
    // direct property assignment on a pre-authorization skeleton.
    protected $fillable = [
        'title',
        'sort_order',
        // 'fresha' = auto-created from a Fresha category label during
        // projection; NULL = owner-authored.
        'source',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'site.service_category_assignments', 'service_category_id', 'service_id')
            ->withTimestamps();
    }

    protected static function booted(): void
    {
        // Qualified columns: the multi-category pivot join (Service::categories)
        // carries its own created_at, so the bare name is ambiguous there.
        static::addGlobalScope('ordered', function ($q) {
            $q->orderBy('site.service_categories.sort_order')->orderBy('site.service_categories.created_at');
        });
    }
}
