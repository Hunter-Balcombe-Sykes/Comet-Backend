<?php

namespace App\Site\Presets;

use App\Models\Core\Site\Page;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\Site;
use App\Services\Content\PageCapabilities;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a site's pages, sections and field bindings from its preset — the
 * cold-build entry §14 references, and the OnboardingSuggestions successor.
 *
 * ADDITIVE, always (§14): instantiation runs at build with whatever sector is
 * known, and when sector lands later (post-claim picker) a re-run refines by
 * adding what is missing — it never rearranges, relabels or deletes anything
 * the site already has. A page key that exists is the user's (or an earlier
 * preset's) and is skipped whole; a section key that exists is skipped.
 *
 * Capability gates apply at seed time exactly as at write time: a page whose
 * capability this account lacks (a partna cafe's Menu) is not created —
 * PageCapabilities is the same closed registry the controllers enforce, so
 * the instantiator can never mint what a request could not.
 *
 * Kit seeding is deliberately absent: SectorStylePresets is a read-time
 * overlay (manual always wins per column), so the preset's look applies the
 * moment the sector is known with nothing to write and nothing to migrate.
 */
class PresetInstantiator
{
    /**
     * @return array{preset: string, createdPages: list<string>, createdSections: list<string>}
     */
    public function instantiate(Site $site): array
    {
        $user = $site->user;
        $preset = PresetLibrary::forUser($user);
        $siteId = (string) $site->id;

        $created = ['pages' => [], 'sections' => []];

        DB::connection('pgsql')->transaction(function () use ($user, $preset, $siteId, &$created) {
            $existingPages = Page::query()->where('site_id', $siteId)->get()->keyBy('key');
            $existingSectionKeys = Section::query()->where('site_id', $siteId)
                ->whereNotNull('key')->pluck('key')->flip();

            $pageSort = (int) ($existingPages->max('sort_order') ?? -1) + 1;

            foreach (PresetLibrary::pages($preset) as $spec) {
                if ($user !== null && ! PageCapabilities::allows($user, $spec['capability'])) {
                    continue;
                }

                $page = $existingPages->get($spec['key']);

                if ($page === null) {
                    $page = new Page([
                        'key' => $spec['key'],
                        'label' => $spec['label'],
                        'sort_order' => $pageSort++,
                        'capability' => $spec['capability'],
                        'preset_key' => $preset,
                    ]);
                    $page->site_id = $siteId;
                    $page->save();
                    $created['pages'][] = $spec['key'];
                } elseif ($page->preset_key === null) {
                    // Not ours to touch: a user-made page keeps everything,
                    // including the absence of preset lineage.
                    continue;
                }

                $sectionSort = (int) Section::query()->where('page_id', $page->id)->max('sort_order') + 1;

                foreach ($spec['sections'] as $sectionSpec) {
                    if (isset($existingSectionKeys[$sectionSpec['key']])) {
                        continue;
                    }

                    $section = new Section([
                        'key' => $sectionSpec['key'],
                        'label' => $sectionSpec['label'],
                        'slot' => $sectionSpec['slot'],
                        'kind' => $sectionSpec['kind'],
                        'sort_order' => $sectionSort++,
                        'rule' => $sectionSpec['rule'],
                        'mode' => $sectionSpec['mode'],
                        'order_by' => $sectionSpec['order_by'],
                        'limit_n' => $sectionSpec['limit_n'],
                        'group_by' => $sectionSpec['group_by'],
                        'render' => $sectionSpec['render'],
                        'price_mode' => $sectionSpec['price_mode'],
                        'min_items' => $sectionSpec['min_items'],
                        'on_empty' => $sectionSpec['on_empty'],
                        'stale_display' => $sectionSpec['stale_display'],
                    ]);
                    $section->page_id = $page->id;
                    $section->site_id = $siteId;
                    $section->save();
                    $created['sections'][] = $sectionSpec['key'];
                }
            }
        });

        if ($created['pages'] !== [] || $created['sections'] !== []) {
            BuildState::bump($siteId);
        }

        return [
            'preset' => $preset,
            'createdPages' => $created['pages'],
            'createdSections' => $created['sections'],
        ];
    }
}
