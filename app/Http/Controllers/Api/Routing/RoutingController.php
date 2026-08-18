<?php

namespace App\Http\Controllers\Api\Routing;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Routing\RouteLinkRequest;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\LinkInBioScanJob;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Routing\SecretParams;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\LinkInBioDetector;
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
        $url = $request->validated()['url'];
        if (app(LinkInBioDetector::class)->matches($url)) {
            return $this->success([
                'verdict' => 'note',
                'canonicalUrl' => trim($url),
                'routedTo' => null,
                'confidence' => null,
                'blockReason' => null,
                'explanation' => "We'll pull the links from this page onto your site.",
                'conflictingConnectionId' => null,
            ]);
        }
        $result = $this->routing->preview($url, RoutingContext::forUser($user, 'paste'));

        return $this->success($result);
    }

    public function store(RouteLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $url = $request->validated()['url'];
        // A pasted link-in-bio page (linktr.ee, beacons.ai, msha.ke, stan.store)
        // used to bounce as `reject: shortener`. It is a bundle of the user's
        // own links: unroll it (every outbound link goes through LinkRouter
        // via LinkInBioScanJob) and keep the page itself as a link card, so
        // nothing the owner pasted is lost (overnight 2026-08-18 F15).
        if (app(LinkInBioDetector::class)->matches($url)) {
            $write = $this->links->addManual($user, trim($url));
            if ($write['status'] === 'cap_full') {
                return $this->error('You can add up to '.CustomLinkSeeder::MAX_LINKS.' links.', 422, extra: ['code' => 'link_cap_reached']);
            }
            if ($write['status'] === 'busy') {
                return $this->error('Another change is still saving — please retry in a moment.', 423);
            }
            if ($write['status'] === 'unavailable') {
                return $this->error('This integration is currently unavailable.', 503);
            }
            // Dispatched AFTER the card write settled: a cap-full page must not
            // still fan out (review W3).
            LinkInBioScanJob::dispatch((string) $user->id, trim($url), AccountCapabilities::for($user)->can_use_booking);

            return $this->success([
                'status' => 'pending',
                'outcome' => in_array($write['status'], ['created', 'exists'], true) ? 'link' : null,
                'verdict' => 'note',
                'canonicalUrl' => trim($url),
                'routedTo' => null,
                'confidence' => null,
                'blockReason' => null,
                'explanation' => "We'll pull the links from this page onto your site.",
                'conflictingConnectionId' => null,
                'connectionId' => null,
                'unrolled' => true,
            ], 202);
        }

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
            // Keyed on STATUS, not on a returned row. Convergence Phase 6 moved
            // this write onto the custom_links pool, and there is no connection
            // to hand back any more — `row` is null on every path, so the old
            // `!== null` test silently stopped reporting outcome 'link' at all.
            if ($write['status'] === 'created' || $write['status'] === 'exists') {
                $outcome = 'link';
            }
            // Storefront probe for a link the catalog could not place (owner
            // ask, 2026-08-18): async and budgeted (ProbeGate); a Shopify /
            // WooCommerce / Squarespace / Big Cartel storefront comes back as
            // a SUGGESTION ("Is this your store?"), never an auto-connect —
            // the user pasted a link, not a store. A miss changes nothing:
            // the link card above already exists.
            if (($result['probe'] ?? null) === 'store' && ($result['canonicalUrl'] ?? null) !== null) {
                CommerceProbeJob::dispatch((string) $user->id, (string) $result['canonicalUrl'], suggestOnly: true);
            }
        }

        // 'ok' when something was actually connected; 'pending' otherwise —
        // matching the legacy contract, where pending meant "we accepted it,
        // work continues". A suggestion or a link item is exactly that.
        $status = $result['connectionId'] !== null ? 'ok' : 'pending';

        return $this->success(['status' => $status, 'outcome' => $outcome] + $result, 202);
    }
}
