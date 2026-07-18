<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/**
 * One targeting criterion in a segment definition.
 *
 * Each implementation owns BOTH its validation rules and its query
 * compilation, so the two can never drift apart. Register in SegmentCriteria.
 */
interface SegmentCriterion
{
    /**
     * Every `filters` key this criterion owns. Most own one; range criteria
     * own a min/max pair. Used by the registry-coverage test, not the resolver.
     *
     * @return list<string>
     */
    public function keys(): array;

    /**
     * Validation rules merged into StoreSegmentRequest::rules().
     * Keys are dot-paths including the `filters.` prefix.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Is this criterion engaged for the given filter definition? A segment
     * where NO criterion is active resolves to the empty dynamic set.
     */
    public function isActive(array $filters): bool;

    /** Constrain the core.users query. Only ever called when isActive() is true. */
    public function apply(Builder $query, array $filters): void;
}
