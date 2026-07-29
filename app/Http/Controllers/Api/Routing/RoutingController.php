<?php

namespace App\Http\Controllers\Api\Routing;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Routing\RouteLinkRequest;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Routing\SecretParams;
use App\Services\Platforms\CustomLinkSeeder;
use Illuminate\Http\JsonResponse;

/**
 * The named successor to CustomLinksController::addLink (plan §2).
 *
 *   POST /api/routing/preview — decide + explain, write nothing (debounced
 *   as the user types a URL).
 *   POST /api/routing/links   — observe → project → place → reconcile.
 *
 * The 202 envelope and `routedTo` shape are deliberately compatible with the
 * legacy endpoint so the frontend can move one screen at a time rather than
 * in a single flag day.
 */
class RoutingController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly LinkRoutingService $routing,
        private readonly CustomLinkSeeder $links,
    ) {}

    public function preview(RouteLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $result = $this->routing->preview(
            $request->validated()['url'],
            RoutingContext::forUser($user, 'paste'),
        );

        return $this->success($result);
    }

    public function store(RouteLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $url = $request->validated()['url'];
        $result = $this->routing->route($url, RoutingContext::forUser($user, 'paste'));

        // What actually happened, in one word the dashboard can switch on:
        //   connected — a connection exists now
        //   review    — an intent was written; the suggestions inbox owns it
        //   link      — kept as a plain link card (Verdict::Note's promise)
        //   null      — refused (reject), or nothing could be written
        $outcome = match (true) {
            $result['connectionId'] !== null => 'connected',
            in_array($result['verdict'], ['choose', 'hold'], true) => 'review',
            default => null,
        };

        // Note = "keep as a link item, never dropped". The reconciler only
        // writes intents/connections, so without this branch a noted URL
        // returned 202 "pending" and then vanished. Reuses the legacy
        // custom-link write (same row, cap, dedupe, enrichment) so
        // GET /platforms/custom/links and the sitepage pick it up unchanged.
        if ($result['verdict'] === 'note') {
            // redactUrl() fails closed (returns '' on a PCRE engine error), so
            // this fallback is unreachable for the validated, non-null $url —
            // but never fall back to the raw, possibly-secret-bearing URL.
            $write = $this->links->addManual($user, $result['canonicalUrl'] ?? SecretParams::redactUrl($url) ?? '');

            if ($write['status'] === 'cap_full') {
                return $this->error(
                    'You can add up to '.CustomLinkSeeder::MAX_LINKS.' links.',
                    422,
                    extra: ['code' => 'link_cap_reached'],
                );
            }
            if ($write['status'] === 'busy') {
                return $this->error('Another change is still saving — please retry in a moment.', 423);
            }
            if ($write['status'] === 'unavailable') {
                return $this->error('This integration is currently unavailable.', 503);
            }
            if ($write['row'] !== null) {
                $outcome = 'link';
            }
        }

        // 'ok' when something was actually connected; 'pending' otherwise —
        // matching the legacy contract, where pending meant "we accepted it,
        // work continues". A suggestion or a link item is exactly that.
        $status = $result['connectionId'] !== null ? 'ok' : 'pending';

        return $this->success(['status' => $status, 'outcome' => $outcome] + $result, 202);
    }
}
