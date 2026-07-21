<?php

namespace App\Models;

use App\Database\Relations\SchemaQualifiedBelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

// V2: Abstract base for all models. Forces the pgsql connection so no model accidentally hits SQLite or another DB.
abstract class BaseModel extends Model
{
    protected $connection = 'pgsql';

    /**
     * Every belongsToMany here spans schema-qualified tables — use the variant
     * whose star select stays valid on the SQLite test mirror (see
     * SchemaQualifiedBelongsToMany).
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @return SchemaQualifiedBelongsToMany<TRelatedModel, TDeclaringModel, Pivot, 'pivot'>
     */
    protected function newBelongsToMany(Builder $query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null)
    {
        return new SchemaQualifiedBelongsToMany($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }
}
