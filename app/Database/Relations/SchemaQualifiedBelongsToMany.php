<?php

namespace App\Database\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * BelongsToMany for schema-qualified tables (every model here lives in a
 * Postgres schema, e.g. `site.menu_categories`).
 *
 * The stock relation selects `<related table>.*`, which the grammar renders as
 * `"site"."menu_categories".*` — valid Postgres, but a SYNTAX ERROR on the
 * SQLite test mirror: SQLite's result-column grammar allows `table.*` and
 * plain `*`, never the three-part `schema.table.*` (attached-database tables
 * are star-selected by bare table name only). Column REFERENCES
 * (`"site"."t"."col"`) are fine on both — only the star form differs.
 *
 * Fix: let the star ride the implicit table alias (`menu_categories.*`), which
 * both engines accept because a schema-qualified FROM/JOIN still exposes the
 * bare table name as its alias. Everything else is stock BelongsToMany.
 *
 * Wired through BaseModel::newBelongsToMany() so every belongsToMany in the
 * codebase gets it automatically.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 * @template TPivotModel of \Illuminate\Database\Eloquent\Relations\Pivot = \Illuminate\Database\Eloquent\Relations\Pivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 */
class SchemaQualifiedBelongsToMany extends BelongsToMany
{
    /** {@inheritdoc} */
    protected function shouldSelect(array $columns = ['*'])
    {
        if ($columns == ['*']) {
            $bareTable = last(explode('.', (string) $this->related->getTable()));
            $columns = [$bareTable.'.*'];
        }

        return array_merge($columns, $this->aliasedPivotColumns());
    }
}
