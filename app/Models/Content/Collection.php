<?php

namespace App\Models\Content;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Generic grouping row (migration 20260727140000). Shop uses kind='storefront';
 * slices 3/4 will add theirs (services, menu categories).
 *
 * @property string $id
 * @property string $user_id FK -> core.users.id. Tenancy — assigned via associate(), never mass-assigned.
 * @property string|null $parent_id FK -> content.collections.id (self-referential).
 * @property string $label
 * @property string|null $kind
 * @property int $position
 * @property bool $is_user_created
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Storefront|null $storefront
 */
class Collection extends BaseModel
{
    use HasUuids;

    protected $table = 'content.collections';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id is tenancy — assigned via associate(), never mass-assigned.
    protected $fillable = ['parent_id', 'label', 'kind', 'position', 'is_user_created'];

    protected function casts(): array
    {
        return ['is_user_created' => 'boolean', 'position' => 'integer'];
    }

    /** @return HasOne<Storefront, $this> */
    public function storefront(): HasOne
    {
        return $this->hasOne(Storefront::class, 'collection_id');
    }
}
