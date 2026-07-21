<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('ordered', function ($q) {
            $q->orderBy('sort_order')->orderBy('created_at');
        });
    }
}
