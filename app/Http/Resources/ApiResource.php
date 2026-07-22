<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Doctrine base for every API Resource (#RES-5 / bundle B5).
 *
 * Contract:
 *   - Resources emitting an `id` field MUST cast it to string:
 *     `'id' => (string) $this->id`.
 *     UUIDs serialise the same either way, but the cast keeps the type
 *     stable for strict-typed consumers (Zod schemas, TS discriminated
 *     unions) and survives a future int-keyed table without a contract
 *     break.
 *   - Resources are explicit allowlists, never `$this->resource->toArray()`
 *     style passthroughs (see #RES-7 / B4 P2-35).
 */
abstract class ApiResource extends JsonResource {}
