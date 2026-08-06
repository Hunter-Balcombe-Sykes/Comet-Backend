<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The hand-add half of a pool (platforms-as-sources): POST a URL, get a
// MANUAL-source content item, pinned into the selection. The manual source
// is the content schema's own concept (content.sources kind='manual', one
// per user, find-or-create) — hand-adds ride the same spine as synced items,
// so overrides, links, removal and the public payload treat them alike.
//
// Deliberately thin on enrichment: the headline defaults to the URL's host
// when the caller sends none, and the ingest/identity lane may enrich later.
// A hand-add is the owner typing a link they already know — the honest
// contract is "it appears, titled what you called it".
class PoolItemCreateController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly PoolResolver $resolver,
        private readonly PoolSectionProvisioner $provisioner,
    ) {}

    /** POST /api/content/pools/{pool}/items  { url, title?, kind? } */
    public function store(Request $request, string $pool): JsonResponse
    {
        if (! PoolRegistry::isPool($pool)) {
            abort(404, 'Unknown pool.');
        }

        $kinds = PoolRegistry::kinds($pool);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'title' => ['nullable', 'string', 'max:300'],
            // The pool's own kinds only — "episode" can't land in Watch.
            'kind' => ['nullable', 'string', 'in:'.implode(',', $kinds)],
        ]);

        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $kind = $data['kind'] ?? $kinds[0];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = (string) (parse_url($data['url'], PHP_URL_HOST) ?: $data['url']);
        }

        $itemId = DB::connection('pgsql')->transaction(function () use ($user, $data, $kind, $title) {
            $sourceId = DB::connection('pgsql')->table('content.sources')
                ->where('user_id', $user->id)
                ->where('kind', 'manual')
                ->value('id');

            if ($sourceId === null) {
                $sourceId = (string) Str::uuid();
                DB::connection('pgsql')->table('content.sources')->insert([
                    'id' => $sourceId, 'user_id' => $user->id, 'kind' => 'manual',
                    'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $itemId = (string) Str::uuid();
            DB::connection('pgsql')->table('content.items')->insert([
                'id' => $itemId, 'user_id' => $user->id, 'kind' => $kind,
                'headline_cache' => $title,
                'facets_cache' => '[]', 'eligible_cache' => '[]',
                'first_seen_at' => now(), 'last_seen_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::connection('pgsql')->table('content.source_items')->insert([
                'id' => (string) Str::uuid(), 'source_id' => $sourceId,
                'coord' => 'manual:'.$itemId, 'item_id' => $itemId, 'kind' => $kind,
                'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
            DB::connection('pgsql')->table('content.f_text')->insert([
                'item_id' => $itemId, 'source_id' => $sourceId,
                'headline' => $title, 'updated_at' => now(),
            ]);
            DB::connection('pgsql')->table('content.f_link')->insert([
                'item_id' => $itemId, 'source_id' => $sourceId,
                'url' => $data['url'], 'updated_at' => now(),
            ]);

            return $itemId;
        });

        // A hand-add is a pick by definition — pin it at the end.
        $section = $this->provisioner->ensure($site, $pool);
        $highest = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');
        $pin = new SectionItem;
        $pin->section_id = (string) $section->id;
        $pin->item_id = $itemId;
        $pin->state = SectionItem::STATE_PINNED;
        $pin->sort_key = $highest === null ? 1.0 : ((float) $highest) + 1.0;
        $pin->created_at = now();
        $pin->save();

        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        return $this->success($this->resolver->resolve($site, $pool), 201);
    }
}
