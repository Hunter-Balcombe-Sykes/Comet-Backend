<?php

namespace App\Services\Site;

use App\Models\Core\Site\Block;
use App\Services\User\SectionVisibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

// T15 (2026-08-27): the section-block provisioning loop, extracted VERBATIM
// from UserSectionBlockController::syncAllowedSections so the pre-account
// pipeline can provision the same rows with the same seeding rules. Before
// this, blocks were only ever created by the dashboard's GET /api/sections —
// so an unclaimed build that wrote a workplace row (the Fresha → Places
// linker) had no `workplace` block and sectionEnvelope() kept the workplace
// invisible on the live site forever (issue 9, verified on barber-in-law).
class SectionBlockProvisioner
{
    /**
     * Sections seeded LIVE (owner ruling 2026-08-19): both render on the
     * sitepage the moment their underlying data exists, and their dashboard
     * switch is opt-OUT. Everything else starts draft and the owner publishes.
     *
     * @var list<string>
     */
    public const SEEDED_LIVE_SECTIONS = ['workplace', 'public_contact'];

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    /**
     * Ensure every allowed section has a row. Never touches existing rows'
     * is_enabled / is_active — those are owned by the visibility service and
     * the pro's Draft/Live toggle respectively. New rows are seeded with
     * is_enabled reflecting the current data state.
     *
     * Never changes sort_order for existing blocks — only assigns one to new
     * blocks (max existing + 1) to avoid conflicts with the partial unique
     * index on (site_id, block_group, sort_order) WHERE block_group = 'sections'.
     *
     * @param  array<int, mixed>  $allowedSections  raw config values — filtered here
     * @return Collection<int, Block>
     */
    public function syncAllowed(string $userId, string $siteId, array $allowedSections): Collection
    {
        $orderedAllowed = array_values(array_unique(array_filter($allowedSections, static fn ($value) => is_string($value))));

        return DB::transaction(function () use ($userId, $siteId, $orderedAllowed) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$siteId}"]);

            // Query ALL section blocks (not just allowed types) so the max sort_order
            // calculation accounts for every existing row and new blocks are never
            // inserted at a position already held by a non-allowed block.
            $allBlocks = Block::query()
                ->where('user_id', $userId)
                ->where('site_id', $siteId)
                ->where('block_group', Block::GROUP_SECTIONS)
                ->get();

            $byType = $allBlocks->keyBy('block_type');
            $maxSortOrder = $allBlocks->max('sort_order') ?? -1;

            foreach ($orderedAllowed as $blockType) {
                $existing = $byType->get($blockType);
                if ($existing) {
                    // Existing row: leave is_enabled / is_active untouched.
                    continue;
                }

                // user_id/site_id removed from $fillable (S4 Tier 2b) — set
                // directly; both are NOT NULL so a silent drop would 500 at save().
                $block = new Block([
                    'block_group' => Block::GROUP_SECTIONS,
                    'block_type' => $blockType,
                ]);
                $block->user_id = $userId;
                $block->site_id = $siteId;

                $block->settings = [];
                // Sections start OFF and the owner publishes them — except the
                // two in SEEDED_LIVE_SECTIONS, whose dashboard switch is opt-OUT.
                $block->is_active = in_array($blockType, self::SEEDED_LIVE_SECTIONS, true);
                $block->sort_order = ++$maxSortOrder;

                // Seed is_enabled honestly from current data state. One exists()
                // per new block — only fires on first-time setup, so the cost is
                // one-shot, not hot-path.
                [$canBeEnabled] = $this->visibilityService->checkVisibilityRequirements(
                    $userId,
                    $siteId,
                    $blockType,
                );
                $block->is_enabled = $canBeEnabled;

                $block->save();
                $byType->put($blockType, $block);
            }

            return $byType->values();
        });
    }
}
