<?php

namespace App\Http\Resources;

use App\Models\Core\User\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Slice 3b Task 9: one wire shape, two backing stores.
 *
 * The owner-facing `/service-categories/*` routes now hand this resource a
 * `content.collections` row (via ServiceCollections); the staff routes still
 * hand it a legacy `site.service_categories` model. Both emit the identical
 * field list — the store moved, the contract did not.
 *
 * The collection row is NOT simply re-fed through the legacy mapping: its
 * columns are named differently (label / position / removed_at) and, worse,
 * `source` would come back null for EVERY category, including Fresha-derived
 * ones — a silent regression on the one field the dashboard uses to tell a
 * synced category from an editable one.
 */
class ServiceCategoryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource instanceof ServiceCategory
            ? $this->fromLegacyModel($this->resource)
            : $this->fromCollectionRow((object) $this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    private function fromLegacyModel(ServiceCategory $category): array
    {
        return [
            'id' => (string) $category->id,
            'user_id' => $category->user_id,
            'title' => $category->title,
            // 'fresha' = auto-created from a Fresha category during
            // projection; NULL = owner-authored. Without it the dashboard
            // couldn't tell a synced category from an editable one —
            // ServiceResource and the menu payload both already say whose
            // row it is. Typed access (not `$this->source`): those ride the
            // 2026-06-03 PHPStan baseline; new code is gated at level 5 and
            // doesn't get to add to it.
            'source' => $category->source,
            'sort_order' => $category->sort_order,
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
            'deleted_at' => $category->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * A ServiceCollections row: id, label, position, external_ref,
     * is_user_created, removed_at, item_count (+ user_id, stamped on by the
     * controller — the query is owner-scoped and carries no such column).
     *
     * @return array<string, mixed>
     */
    private function fromCollectionRow(object $row): array
    {
        return [
            'id' => (string) $this->column($row, 'id'),
            'user_id' => $this->column($row, 'user_id'),
            'title' => $this->column($row, 'label'),
            // is_user_created arrives as a real bool (ServiceCollections
            // normalises it — PDO_PGSQL hands booleans back as "t"/"f", so a
            // loose check here would call every Fresha category owner-made on
            // real Postgres and never on the SQLite test lane). false = the
            // projector created it from a vendor category, which today only
            // ever means Fresha; true = the owner did, so no source.
            'source' => $this->column($row, 'is_user_created') === false ? 'fresha' : null,
            'sort_order' => (int) $this->column($row, 'position'),
            'created_at' => $this->timestamp($this->column($row, 'created_at')),
            'updated_at' => $this->timestamp($this->column($row, 'updated_at')),
            'deleted_at' => $this->timestamp($this->column($row, 'removed_at')),
        ];
    }

    /** Absent column => null, rather than a PHP warning on an undefined property. */
    private function column(object $row, string $name): mixed
    {
        return property_exists($row, $name) ? $row->{$name} : null;
    }

    /**
     * Query-builder rows hand timestamps back as driver-formatted strings
     * (and SQLite as plain text), never as Carbon — parse before formatting
     * so the wire keeps the ISO-8601 the Eloquent path emits.
     */
    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
