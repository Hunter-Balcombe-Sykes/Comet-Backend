<?php

namespace App\Services\User;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Orchestrates section-visibility checks. Each section type's rule lives in a
// SectionVisibilityContract impl registered in SectionVisibilityRegistry; this
// service loads the shared EXISTS context in one round-trip and delegates the
// per-block decision to the matching rule. Adding a section type touches only the
// registry + a new rule impl, never this file.
class SectionVisibilityService
{
    public function __construct(
        private readonly SectionVisibilityRegistry $registry,
    ) {}

    /**
     * Check if a section type meets its visibility requirements (single-block path).
     *
     * Looks up the rule in the registry; if none is registered for this type (e.g.
     * contacts_collection, newsletter, bio — no data requirement) returns [true, null]
     * immediately. For types that read their own settings (countdown, contact) the
     * orchestrator loads the stored block (or a skeleton with empty settings) and
     * passes $pendingSettings so a first-publish request that sends settings + live
     * together sees the merged shape before saving.
     *
     * @param  array<string, mixed>|null  $pendingSettings  Incoming-but-not-yet-persisted settings,
     *                                                      merged over stored for types whose
     *                                                      requirement lives in their own payload
     *                                                      (countdown, contact). Others ignore it.
     * @return array{0: bool, 1: ?string} [canBeVisible, reason]
     */
    public function checkVisibilityRequirements(
        string $userId,
        string $siteId,
        string $blockType,
        ?array $pendingSettings = null,
    ): array {
        $rule = $this->registry->get($blockType);
        if ($rule === null) {
            return [true, null];
        }

        $context = $this->buildContext($userId, $siteId, [$blockType]);
        $block = $this->loadSectionBlock($userId, $siteId, $blockType);

        return $rule->resolve($block, $context, $pendingSettings);
    }

    /**
     * Batch-evaluate visibility for a set of already-loaded section blocks.
     *
     * Loads each visibility data-source at most once (and only when at least one
     * section in the input requires it) in a single SELECT, then resolves every
     * block against that shared context.
     *
     * @param  iterable<Block>  $sectionBlocks  Already-loaded blocks; their stored settings are
     *                                          used for countdown/contact/booking (no DB call).
     * @return array<string, array{0: bool, 1: ?string}> Map of block_type → [canBeVisible, reason]
     */
    public function batchCheck(string $userId, string $siteId, iterable $sectionBlocks): array
    {
        $blocks = $sectionBlocks instanceof Collection
            ? $sectionBlocks
            : Collection::make($sectionBlocks);

        $types = $blocks->pluck('block_type')
            ->filter(fn ($t) => is_string($t))
            ->unique()
            ->values()
            ->all();

        $context = $this->buildContext($userId, $siteId, $types);

        $byType = [];
        foreach ($blocks as $block) {
            $type = (string) ($block->block_type ?? '');
            if ($type === '' || array_key_exists($type, $byType)) {
                continue;
            }

            $rule = $this->registry->get($type);
            $byType[$type] = $rule === null
                ? [true, null]
                : $rule->resolve($block, $context, null);
        }

        return $byType;
    }

    /**
     * Re-evaluate and persist is_enabled for a section block based on its requirements.
     * is_active (the professional's show/hide preference) is never touched.
     */
    public function reevaluateEnabled(string $userId, string $siteId, string $blockType): void
    {
        $block = Block::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', $blockType)
            ->first();

        if (! $block) {
            return;
        }

        try {
            $rule = $this->registry->get($blockType);
            if ($rule === null) {
                $canBeEnabled = true;
            } else {
                // Reuse the already-loaded block; no pending settings (post-save).
                $context = $this->buildContext($userId, $siteId, [$blockType]);
                [$canBeEnabled] = $rule->resolve($block, $context, null);
            }

            if ((bool) $block->is_enabled !== $canBeEnabled) {
                $block->is_enabled = $canBeEnabled;
                $block->save();
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Section is_enabled reevaluation failed', [
                'user_id' => $userId,
                'site_id' => $siteId,
                'block_type' => $blockType,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the visibility data context for the present section types in a single
     * round-trip. Each rule contributes zero or more EXISTS subqueries (keyed by a
     * context alias); they are bundled into ONE SELECT, so we pay one network
     * round-trip instead of N. Rules whose requirement lives in the block's own
     * settings (countdown, contact) contribute no subquery. Bindings accumulate in
     * select-clause order, matching the placeholder order in the compiled SQL.
     *
     * @param  array<int, string>  $presentTypes
     * @return array<string, bool|null> alias => exists-result
     */
    private function buildContext(string $userId, string $siteId, array $presentTypes): array
    {
        $subqueries = [];
        foreach ($presentTypes as $type) {
            $rule = $this->registry->get($type);
            if ($rule === null) {
                continue;
            }
            foreach ($rule->contextSubqueries($userId, $siteId) as $alias => $builder) {
                $subqueries[$alias] = $builder;
            }
        }

        if (empty($subqueries)) {
            return [];
        }

        // All models in this codebase use the 'pgsql' connection (enforced by BaseModel).
        // Scoping here explicitly ensures the combined SELECT runs on the same connection
        // as the subquery builders, even in test environments where the default connection
        // differs (SectionVisibilityTestCase sets default = 'sqlite').
        $query = DB::connection('pgsql')->query();
        foreach ($subqueries as $alias => $sub) {
            $query->selectRaw('exists ('.$sub->toSql().') as '.$alias, $sub->getBindings());
        }

        $row = $query->first();
        $context = [];
        if ($row !== null) {
            foreach ($subqueries as $alias => $_) {
                $context[$alias] = isset($row->$alias) ? (bool) $row->$alias : null;
            }
        }

        return $context;
    }

    /**
     * Load the section block for (user, site, type), or a transient skeleton with
     * empty settings when none exists yet (first-publish path). Rules that read the
     * block's own settings (booking legacy url, countdown, contact) operate on this;
     * data-source rules ignore it.
     */
    private function loadSectionBlock(string $userId, string $siteId, string $blockType): Block
    {
        $block = Block::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', $blockType)
            ->first();

        return $block ?? new Block([
            'user_id' => $userId,
            'site_id' => $siteId,
            'block_group' => Block::GROUP_SECTIONS,
            'block_type' => $blockType,
            'settings' => [],
        ]);
    }
}
