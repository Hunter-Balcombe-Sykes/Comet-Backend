<?php

namespace App\Services\User\Visibility;

use App\Models\Core\Site\Block;
use Illuminate\Database\Query\Builder;

// One section type's visibility rule. Registered in SectionVisibilityRegistry,
// keyed by block_type. Modelled on the PlatformRegistry/PlatformDescriptor spine:
// adding a section type = one impl + one register() line, no edits to the
// orchestrating SectionVisibilityService.
interface SectionVisibilityContract
{
    /**
     * The block_type this rule governs (e.g. 'gallery'). Must be one of the
     * 'sections' entries in config('partna.block_types').
     */
    public function blockType(): string;

    /**
     * EXISTS subqueries this rule needs, keyed by context alias. Each Builder is
     * wrapped in `exists (...)` and bundled into ONE SELECT round-trip by the
     * service. Return [] for types whose requirement lives entirely in the
     * block's own settings (countdown, contact).
     *
     * @return array<string, Builder>
     */
    public function contextSubqueries(string $userId, string $siteId): array;

    /**
     * Resolve [canBeVisible, ?reason] for one block against the precomputed context.
     *
     * @param  array<string, bool|null>  $context  Resolved EXISTS results, keyed by alias.
     * @param  array<string, mixed>|null  $pendingSettings  Unsaved settings merged over the
     *                                                      block's stored settings (single-check
     *                                                      path only; null in the batch path).
     * @return array{0: bool, 1: ?string}
     */
    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array;
}
