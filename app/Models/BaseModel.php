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
     * T19 (2026-08-27): microsecond timestamps UNDER TESTS ONLY. Live
     * Postgres stores microseconds natively, but the SQLite test mirror
     * stored second-precision strings — so two writes inside one wall-clock
     * second made the second `touch()` a silent no-op (updated_at not dirty
     * → no saved event → no purge dispatch), which is exactly the flake that
     * made BlockAndMediaTouchSiteTest / ProjectionWriterTest / the Fresha
     * connect tests fail 0–5 times per identical run, by run speed.
     * Production format is untouched.
     */
    public function getDateFormat(): string
    {
        return app()->runningUnitTests() ? 'Y-m-d H:i:s.u' : parent::getDateFormat();
    }

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
