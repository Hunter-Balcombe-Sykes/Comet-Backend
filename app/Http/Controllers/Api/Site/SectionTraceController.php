<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\Section;
use App\Services\Content\SectionTracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/site/sections/{id}/trace` — the 3am story, made concrete (plan §7).
 *
 * Returns a verdict and a reason per candidate: why this item is on the page,
 * why that one is not. On demand rather than always-on, because the cost of
 * explaining every section on every build is paid by every user including the
 * ones who never ask.
 *
 * The trace mirrors what the document builder ACTUALLY does, including the
 * five rule operators it does not execute yet — those are reported in `gaps`
 * rather than described as applied. A diagnostic that disagrees with the live
 * page is worse than no diagnostic.
 */
class SectionTraceController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(private readonly SectionTracer $tracer) {}

    public function show(Request $request, string $sectionId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $section = Section::query()->where('id', $sectionId)->where('site_id', $site->id)->first();

        if ($section === null) {
            abort(404, 'Section not found.');
        }

        $section->setRelation('site', $site);
        $this->authorizeForUser($user, 'view', $section);

        return $this->success(['trace' => $this->tracer->trace($section)]);
    }
}
