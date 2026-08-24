<?php

// Architecture test — every in-scope route carrying a tenant-owned id must be
// classified: probed by zap-active.sh's IDOR_SURFACES table, or excluded with a
// written reason.
//
// WHY: the active lane proves its probes PASS. Nothing proved the probe LIST was
// complete. f13c0e350 took the cross-identity IDOR check from 2 ownership
// deciders to 24 and proved it positively — but nothing stopped #25 being added
// to the app and never probed. Coverage would silently go from *all* to *most*
// while the log kept printing `IDOR assertions passed`.
//
// That is the exact failure SHAPE this whole programme exists to eliminate: the
// lane spent months reporting PASS while completely unauthenticated (71f027b11),
// because a green log is the ABSENCE of a failure line and absence says nothing
// about what was never attempted. A coverage claim with no drift detector is the
// same absence wearing a different hat.
//
// THE UNIT IS THE OWNERSHIP-DECIDING CODE PATH, not the route and not the
// resource — a private lookup helper (findSection/findItem/findIntent/transition)
// or a policy ability. scripts/dast/README.md states the collapse rule and this
// test encodes it: methods sharing a decider collapse to one probe; a method with
// its OWN decider gets its own row even inside the same class. UserEnquiry-
// Controller::markRead/markReplied/archive/restore all delegate to transition()
// (:131), so one probe covers four; markSpam duplicates the lookup inline
// (:56-64), so it is probed separately.
//
// WHAT THIS GUARD DOES NOT ENFORCE, stated plainly so a green run is not read as
// more than it is: it enforces DECIDER-level coverage, not VERB-level. README's
// "What this still does NOT prove" §1 already records that gap — PATCH
// api/gallery/{image} shares SitePolicy with the probed DELETE, so the DECISION
// is covered, but a divergence introduced into one verb's handler alone would not
// be. VERB_VARIANT_OF below is where that acceptance is written down per route
// rather than left implicit. Every entry carries the reason it is acceptable.
//
// THE MAPS ARE HAND-MAINTAINED, DELIBERATELY. Inferring the collapse rule
// statically — "does this method call the same private helper as that one" —
// is the fragile part, and a guard that flags an already-covered decider is worse
// than no guard (CLAUDE.md: "add signals high-confidence only; noisy guards get
// suppressed"). So the detector answers one question only, and answers it
// exactly: is this route's controller method CLASSIFIED? A new method touching a
// tenant-owned id fires once, and the fix is one line declaring which decider it
// collapses into — a forced decision, not noise.
//
// NO SEPARATE CI JOB, deliberately — same reasoning as
// DastSelfDestructionExclusionGuardTest, and for the same reason. outbound-http-
// guard has one (ci.yml) because a green Feature suite is its gate and the suite
// can abort before reaching it, and it protects a live SSRF control on the
// request path. This one protects a manual-only tool's coverage claim: the cost
// of missing it for one CI run is that a future active-lane run under-tests
// something, not that production ships a hole. It runs in `composer test`, which
// is development's real gate. Revisit if the active lane ever becomes gating.

use Illuminate\Support\Facades\Route;

/**
 * The population: routes whose path carries a tenant-owned id, minus the prefixes
 * outside the authenticated user surface the active lane scans.
 *
 * Reproduces the derivation recorded in scripts/dast/README.md (2026-08-09) —
 * `api/staff|public|internal/` are not the scanner's identity, and
 * `api/sessions/`, `api/account/mfa/`, `api/me/deletion/` are the
 * self-destruction class DastSelfDestructionExclusionGuardTest owns.
 *
 * `api/platforms/` IS in the population, since 2026-08-10. It is excluded from
 * the ZAP *scan* (its handlers make real, paid third-party calls) but that is an
 * exclusion from FUZZING, not from AUTHORIZATION probing — a distinction the
 * exclusion was silently conflating. Five of its deciders are probed; the rest
 * are classified in IDOR_UNSAFE_TO_PROBE with the side effect that rules each out.
 */
const IDOR_OUT_OF_POPULATION = [
    'api/staff/',
    'api/public/',
    'api/internal/',
    'api/webhooks/',
    'api/sessions/',
    'api/account/mfa/',
    'api/me/deletion/',
];

/**
 * The path-parameter names treated as tenant-owned ids. The narrow reading, which
 * is the one the README's count is derived from — a wider one sweeps in
 * enum/string keys ({purpose}, {blockType}, {pool}) that no other tenant has a
 * value for, so a probe could not fail.
 */
const IDOR_TENANT_ID_PARAMS = [
    'section', 'item', 'id', 'service', 'notification', 'intent', 'image',
    'customer', 'category', 'upload', 'restyle', 'page', 'feedback',
    'document', 'connection',
    // api/platforms/ only. {platform} is deliberately absent — it is a registry
    // surface key ('spotify', 'eventbrite'), so there is no other tenant's value
    // to substitute and a probe could not fail.
    'productId',
];

/**
 * Controller@method (relative to App\Http\Controllers\Api\) => the IDOR_SURFACES
 * name whose probe exercises that method's ownership decider.
 *
 * @var array<string, string>
 */
const IDOR_COVERED_BY = [
    'User\Customers\UserCustomerController@show' => 'customer',
    'User\Customers\UserEnquiryController@markRead' => 'enquiry-read',
    'User\Customers\UserEnquiryController@markSpam' => 'enquiry-spam',
    'User\SiteManagement\UserGalleryController@destroy' => 'gallery-image',
    'User\Uploads\UserUploadController@destroy' => 'upload-image',
    'User\Account\UserDocumentController@destroy' => 'document',
    'User\Content\ContentController@destroyUpload' => 'content-upload',
    'User\SiteManagement\UserServiceController@show' => 'service',
    'User\SiteManagement\UserServiceCategoryController@show' => 'service-category',
    'Site\SectionController@show' => 'section',
    'Site\SectionItemController@index' => 'section-items',
    'Site\SectionGroupController@index' => 'section-groups',
    'Site\SectionTraceController@show' => 'section-trace',
    'Site\PageController@destroy' => 'page',
    'User\Feedback\FeedbackController@show' => 'feedback',
    'Site\RestyleController@undo' => 'restyle-undo',
    'User\Notifications\NotificationController@markRead' => 'notification-read',
    'Content\ItemController@destroy' => 'content-item',
    'Content\PoolController@deselect' => 'pool-deselect',
    'Content\ItemLinkController@destroy' => 'item-link',
    'Content\ManualOverrideController@destroy' => 'item-override',
    'Routing\ConnectionsController@setPrimary' => 'routing-primary',
    'Routing\SuggestionsController@dismiss' => 'routing-suggestion-dismiss',
    'Site\SectionItemController@upsert' => 'section-item-upsert',
    // api/platforms/* — authorization probes only; see zap-active.sh's
    // platform block for why these five and not the others.
    'Platforms\GenericPlatformController@removeAccount' => 'platform-account',
    'Platforms\EventbriteController@removeAccount' => 'platform-events-account',
    'Platforms\EventbriteController@removeEvent' => 'platform-events-event',
    // platform-custom-link / platform-custom-event left with the retired
    // pseudo-platform controllers (2026-08-19) — their probes went from
    // zap-active.sh in the same change.
];

/**
 * Controller@method => [the covered method it shares a decider with, why that is
 * acceptable]. These are collapses, not gaps: the ownership DECISION is the same
 * code, reached by a different verb or a different public entry point.
 *
 * Verified from source, not assumed — each reason names the line that proves it.
 *
 * @var array<string, array{0: string, 1: string}>
 */
const IDOR_VERB_VARIANT_OF = [
    // Content item verbs share ResolvesOwnedItem::ownedItemOr404() — the one
    // where('user_id')->whereNull('removed_at') → 404 decider the content-item
    // probe covers. identity/store validates {other} through the same helper.
    'Content\\IdentityDecisionController@store' => [
        'Content\\ItemController@destroy',
        'Delegates to the shared ResolvesOwnedItem::ownedItemOr404() helper, same lookup + 404.',
    ],
    // UserEnquiryController: four transitions, one decider. :131 transition()
    // does the ->where('user_id')->find() and the authorize; the four public
    // methods differ only in the closure they pass it.
    'User\Customers\UserEnquiryController@markReplied' => [
        'User\Customers\UserEnquiryController@markRead',
        'Delegates to the shared transition() helper (:131), same lookup + authorize.',
    ],
    'User\Customers\UserEnquiryController@archive' => [
        'User\Customers\UserEnquiryController@markRead',
        'Delegates to the shared transition() helper (:131), same lookup + authorize.',
    ],
    'User\Customers\UserEnquiryController@restore' => [
        'User\Customers\UserEnquiryController@markRead',
        'Delegates to the shared transition() helper (:131), same lookup + authorize.',
    ],

    // SuggestionsController: accept and dismiss both call findIntent (:132).
    // accept additionally runs SuggestionApplier, which is why dismiss is the
    // probed one — same decider, no side effect.
    'Routing\SuggestionsController@accept' => [
        'Routing\SuggestionsController@dismiss',
        'Both call the private findIntent() (:132); accept additionally runs SuggestionApplier, so dismiss is the probed one.',
    ],

    // SectionGroupController: index/upsert/destroy all route through the private
    // findSection() (:96). {groupKey} is a section-scoped string key
    // (->where('group_key', ...)), so the parent {section} is the only
    // tenant-owned id and it is what index probes.
    'Site\SectionGroupController@upsert' => [
        'Site\SectionGroupController@index',
        'Shares the private findSection() (:96); {groupKey} is a section-scoped string key, not a tenant id.',
    ],
    'Site\SectionGroupController@destroy' => [
        'Site\SectionGroupController@index',
        'Shares the private findSection() (:96); {groupKey} is a section-scoped string key, not a tenant id.',
    ],

    // SectionItemController::destroy scopes {item} by section_id alone, never by
    // owner — which is SUFFICIENT: a foreign item cannot be pinned in a section
    // you own, so the parent {section} is the real target. upsert is the
    // exception and has its own probe (section-item-upsert), because it
    // owner-scopes {item} independently via findItem (:151).
    'Site\SectionItemController@destroy' => [
        'Site\SectionItemController@index',
        'Shares findSection() (:133); scopes {item} by section_id alone, which is sufficient — a foreign item cannot be pinned in a section you own.',
    ],

    // Policy-ability variants. CustomerPolicy::view and ::update each duplicate
    // the ownership comparison inline and ::delete delegates to ::update, so
    // strictly these are two textual copies of one check. Recorded rather than
    // folded silently: a divergence introduced into ::update alone would not be
    // caught by the ::view probe.
    'User\Customers\UserCustomerController@update' => [
        'User\Customers\UserCustomerController@show',
        'CustomerPolicy::update duplicates ::view\'s ownership comparison; PATCH also 422s on UpdateCustomerRequest before the controller runs.',
    ],
    'User\Customers\UserCustomerController@destroy' => [
        'User\Customers\UserCustomerController@show',
        'CustomerPolicy::delete delegates to ::update, which duplicates ::view\'s ownership comparison.',
    ],
    'User\Customers\UserCustomerController@restore' => [
        'User\Customers\UserCustomerController@show',
        'Authorizes CustomerPolicy::update, which duplicates ::view\'s ownership comparison.',
    ],

    // Site-media, service and section write verbs. All share their read verb's
    // policy ability; the PATCH/PUT ones additionally 422 on their FormRequest
    // before the controller method runs, which is why a bodyless probe of them
    // would prove nothing (README, "Probing a surface that needs a request body").
    'User\SiteManagement\UserGalleryController@update' => [
        'User\SiteManagement\UserGalleryController@destroy',
        'Same SitePolicy call; a bodyless PATCH 422s on UpdateGalleryImageRequest before authorization runs.',
    ],
    'User\Account\UserDocumentController@update' => [
        'User\Account\UserDocumentController@destroy',
        'Same pool check + policy call; a bodyless PATCH 422s on its FormRequest first.',
    ],
    'User\SiteManagement\UserServiceController@update' => [
        'User\SiteManagement\UserServiceController@show',
        'Same ServicePolicy resource; a bodyless PATCH 422s on its FormRequest first.',
    ],
    'User\SiteManagement\UserServiceController@updateCategory' => [
        'User\SiteManagement\UserServiceController@show',
        'Same ServicePolicy resource; a bodyless PATCH 422s on its FormRequest first.',
    ],
    'User\SiteManagement\UserServiceController@destroy' => [
        'User\SiteManagement\UserServiceController@show',
        'Same ServicePolicy resource, delete ability.',
    ],
    'User\SiteManagement\UserServiceController@restore' => [
        'User\SiteManagement\UserServiceController@show',
        'Same ServicePolicy resource, update ability.',
    ],
    'User\SiteManagement\UserServiceController@resync' => [
        'User\SiteManagement\UserServiceController@show',
        'Same ServicePolicy resource, update ability.',
    ],
    'User\SiteManagement\UserServiceCategoryController@update' => [
        'User\SiteManagement\UserServiceCategoryController@show',
        'Same ServiceCategory policy resource; a bodyless PATCH 422s on its FormRequest first.',
    ],
    'User\SiteManagement\UserServiceCategoryController@destroy' => [
        'User\SiteManagement\UserServiceCategoryController@show',
        'Same ServiceCategory policy resource, delete ability.',
    ],
    'User\SiteManagement\UserServiceCategoryController@restore' => [
        'User\SiteManagement\UserServiceCategoryController@show',
        'Same ServiceCategory policy resource, update ability.',
    ],
    'Site\SectionController@update' => [
        'Site\SectionController@show',
        'Same SectionPolicy resource; a bodyless PATCH 422s on its FormRequest first.',
    ],
    'Site\SectionController@destroy' => [
        'Site\SectionController@show',
        'Same SectionPolicy resource, delete ability.',
    ],
    'Site\PageController@update' => [
        'Site\PageController@destroy',
        'Same page lookup; a bodyless PATCH 422s on its FormRequest first.',
    ],
    'User\Notifications\NotificationController@dismiss' => [
        'User\Notifications\NotificationController@markRead',
        'Same NotificationPolicy resource and lookup, different state write.',
    ],
    'Content\ItemLinkController@upsert' => [
        'Content\ItemLinkController@destroy',
        'Same item ownership lookup; a bodyless PUT 422s on its FormRequest first.',
    ],
    'Content\ManualOverrideController@upsert' => [
        'Content\ManualOverrideController@destroy',
        'Same item ownership lookup; a bodyless PUT 422s on its FormRequest first.',
    ],
    'Content\PoolController@select' => [
        'Content\PoolController@deselect',
        'Same pool + item ownership lookup, inverse write.',
    ],

    // Humanitix and Eventbrite are both thin subclasses of
    // EventsPlatformController and inherit removeAccount (:191) / removeEvent
    // (:251) unchanged — they override only the scraper bindings and the URL
    // copy. route:list reports the bound subclass, so these read as four actions
    // where the deciding code is two methods. Probing Eventbrite's pair proves
    // Humanitix's; a probe each would be duplicate coverage bought with two more
    // fixture rows.
    'Platforms\HumanitixController@removeAccount' => [
        'Platforms\EventbriteController@removeAccount',
        'Inherits EventsPlatformController::removeAccount (:191) unchanged; the subclass binds only a scraper.',
    ],
    'Platforms\HumanitixController@removeEvent' => [
        'Platforms\EventbriteController@removeEvent',
        'Inherits EventsPlatformController::removeEvent (:251) unchanged; the subclass binds only a scraper.',
    ],
];

/**
 * Controller@method => the external side effect that makes its 200 CONTROL unsafe
 * to issue. These carry a real tenant-owned id and WOULD be probeable; what stops
 * them is cost, not authorization design.
 *
 * A probe without a control is vacuous (a dead app 404s everything), so an unsafe
 * control means the whole surface stays unprobed. Each reason is derived from
 * source — the premise of the platform probes is that authorization can be tested
 * without paying for the third-party call, so "probably local" is not good enough.
 *
 * @var array<string, string>
 */
const IDOR_UNSAFE_TO_PROBE = [

    // 'shop' is one of exactly two hasCompletenessPredicate() platforms
    // (PlatformRegistryServiceProvider:479, the other is fresha), so
    // IntegrationConnectionObserver::deleted() calls site->touch() ->
    // SiteObserver:76 -> SyncSubdomainToKvJob — a real Cloudflare KV write, and
    // the single writer CLAUDE.md names.
    'Platforms\ShopController@removeBrand' => 'shop is completeness-gated, so the delete touches the site and reaches SyncSubdomainToKvJob (a Cloudflare KV write).',
    'Platforms\ShopController@removeProduct' => 'Same completeness-gated site touch as removeBrand.',
    'Platforms\ShopController@updateBrand' => 'Same completeness-gated site touch; also a PATCH, so a bodyless probe 422s before authorization.',
    'Platforms\ShopController@setProducts' => 'Same completeness-gated site touch; also a PUT with a required body.',
    // NOT "makes an outbound call" — checked, and it does not. productsFromClient
    // (:577) FILTERS a client-supplied payload by the store URL, which is why its
    // 422 reads "No products from this store were found in that payload". The real
    // reasons are the FormRequest and the surface it belongs to. Recorded because
    // a plausible-sounding wrong reason is exactly what this map must not collect.
    'Platforms\ShopController@catalog' => 'POST with a required SubmitShopCatalogRequest body, so a bodyless probe 422s before the ownership check; and it belongs to the completeness-gated shop surface above.',
    'Platforms\ShopController@brandProducts' => 'Cache::remember short-circuits a LIVE product scrape (:588), so a cold cache means a real outbound fetch.',
    'Platforms\ShopController@connectStatus' => 'Belongs to the shop surface above; left with it rather than half-covering it.',

    // Every MenuContentController write ends in touchAndRespond() ->
    // invalidator->touchSite(), the same SiteObserver -> SyncSubdomainToKvJob
    // path. It is also the only platform decider whose fixture is not a
    // site.platform_connections row (it needs a site.menus / menu_categories /
    // menu_items tree), so covering it is a separate piece of work, not a line.
    'Platforms\MenuContentController@deleteCategory' => 'touchAndRespond() -> invalidator->touchSite() -> SiteObserver:76 -> SyncSubdomainToKvJob (Cloudflare KV write).',
    'Platforms\MenuContentController@deleteItem' => 'touchAndRespond() -> invalidator->touchSite() -> SiteObserver:76 -> SyncSubdomainToKvJob (Cloudflare KV write).',
    'Platforms\MenuContentController@updateCategory' => 'Same KV-write path; also a PATCH, so a bodyless probe 422s before authorization.',
    'Platforms\MenuContentController@updateItem' => 'Same KV-write path; also a PATCH, so a bodyless probe 422s before authorization.',
];

/**
 * Controller@method => why it carries no IDOR surface at all. Distinct from
 * VERB_VARIANT_OF: nothing here has a probeable tenant-owned id.
 *
 * @var array<string, string>
 */
const IDOR_NOT_A_SURFACE = [
    // (empty today — every non-surface route in this population is filtered out
    // by IDOR_TENANT_ID_PARAMS before reaching the classification. Kept as the
    // declared home for the enum/string-keyed class — api/design-media/{purpose},
    // api/sections/{blockType}, api/content/pools/{pool} — should the parameter
    // filter ever be widened to admit them.)
];

/** The raw IDOR_SURFACES rows parsed out of zap-active.sh. */
function idorSurfaceRows(): array
{
    $script = (string) file_get_contents(base_path('scripts/dast/active/zap-active.sh'));

    if (preg_match('/^IDOR_SURFACES=\((.*?)^\)$/ms', $script, $m) !== 1) {
        throw new RuntimeException(
            'Could not find the IDOR_SURFACES array in scripts/dast/active/zap-active.sh. '.
            'If its shape changed, update this test rather than deleting the assertion.'
        );
    }

    $rows = [];
    foreach (explode("\n", $m[1]) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        // Rows are double-quoted; a body field may contain escaped quotes.
        $line = preg_replace('/^"|"$/', '', $line);
        $parts = explode('|', (string) $line);
        $rows[] = [
            'name' => $parts[0],
            'method' => $parts[1],
            'template' => $parts[2],
            'prefix' => $parts[3] ?? '',
        ];
    }

    return $rows;
}

/** Every route in the population, as ['action' => short Controller@method, 'uri' => ..., 'methods' => ...]. */
function idorPopulationRoutes(): array
{
    $paramAlternation = implode('|', IDOR_TENANT_ID_PARAMS);
    $out = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }
        foreach (IDOR_OUT_OF_POPULATION as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                continue 2;
            }
        }
        if (preg_match('/\{('.$paramAlternation.')\}/', $uri) !== 1) {
            continue;
        }

        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue; // closure route — no controller method to classify
        }

        $out[] = [
            'action' => str_replace('App\\Http\\Controllers\\Api\\', '', $action),
            'uri' => $uri,
            'methods' => array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS'])),
        ];
    }

    return $out;
}

/** True when $uri (a Laravel route uri) can serve the concrete path $path. */
function idorRouteServesPath(string $uri, string $path): bool
{
    $pattern = '#^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($uri, '#')).'$#';

    return preg_match($pattern, $path) === 1;
}

it('probes a real route, with the declared verb, for every IDOR surface', function () {
    // A probe whose route was renamed keeps "passing" — a missing route 404s
    // exactly like a foreign one. Today only the paired control catches that, as
    // a confusing `control-*` failure twelve minutes into a manual run. This
    // catches it in `composer test`.
    $routes = idorPopulationRoutes();

    foreach (idorSurfaceRows() as $row) {
        // Strip the leading slash and neutralise the templating: %s is the
        // varying target, @PREFIX@ is identity A's pinned parent id.
        $path = ltrim($row['template'], '/');
        $path = str_replace('%s', 'ID', $path);
        $path = (string) preg_replace('/@[A-Z_]+@/', 'ID', $path);

        $matches = array_values(array_filter(
            $routes,
            fn (array $r): bool => idorRouteServesPath($r['uri'], $path)
                && in_array($row['method'], $r['methods'], true),
        ));

        expect($matches)->not->toBeEmpty(
            "IDOR surface '{$row['name']}' probes {$row['method']} {$row['template']}, which matches no route in ".
            'route:list. A renamed or removed route makes the probe 404 for the WRONG reason and it keeps '.
            'reporting pass. Fix the template or drop the surface — never leave it pointing at nothing.'
        );
    }
});

it('classifies every in-scope route that carries a tenant-owned id', function () {
    // THE DRIFT DETECTOR. A new controller method taking a tenant-owned id is
    // unclassified, so this fails and names it. The fix is one line: a probe in
    // IDOR_SURFACES + a COVERED_BY entry, or a VERB_VARIANT_OF entry saying which
    // decider it shares and why that is acceptable.
    $unclassified = [];

    foreach (idorPopulationRoutes() as $route) {
        $action = $route['action'];
        if (
            array_key_exists($action, IDOR_COVERED_BY)
            || array_key_exists($action, IDOR_VERB_VARIANT_OF)
            || array_key_exists($action, IDOR_NOT_A_SURFACE)
            || array_key_exists($action, IDOR_UNSAFE_TO_PROBE)
        ) {
            continue;
        }
        $unclassified[$action] = implode('|', $route['methods']).' '.$route['uri'];
    }

    expect($unclassified)->toBe([], sprintf(
        "Unclassified tenant-owned-id route(s) — the IDOR probe list has drifted behind the app:\n%s\n\n".
        "Each needs ONE of:\n".
        "  * a probe in scripts/dast/active/zap-active.sh's IDOR_SURFACES + an IDOR_COVERED_BY entry here;\n".
        "  * an IDOR_VERB_VARIANT_OF entry naming the covered method it shares a decider with, and why;\n".
        "  * an IDOR_NOT_A_SURFACE entry with the reason it carries no probeable id;\n".
        "  * an IDOR_UNSAFE_TO_PROBE entry naming the external side effect its 200 control would fire.\n".
        'Do NOT widen IDOR_OUT_OF_POPULATION to make this pass — that removes the route from the guard '.
        'entirely rather than deciding about it.',
        implode("\n", array_map(
            static fn (string $a, string $r): string => "  - {$a}  ({$r})",
            array_keys($unclassified),
            $unclassified,
        )),
    ));
});

it('keeps the classification maps and IDOR_SURFACES in agreement, both ways', function () {
    // The converse of the drift check. A COVERED_BY entry pointing at a probe
    // that was deleted, or a probe nothing claims to cover, means the two halves
    // have drifted apart — same both-ways discipline as
    // DastSelfDestructionExclusionGuardTest's grep/excludePaths pinning.
    $surfaceNames = array_map(static fn (array $r): string => $r['name'], idorSurfaceRows());
    $claimed = array_values(array_unique(array_values(IDOR_COVERED_BY)));

    sort($surfaceNames);
    sort($claimed);

    expect($claimed)->toEqual(
        $surfaceNames,
        'IDOR_COVERED_BY and zap-active.sh\'s IDOR_SURFACES have drifted. Every surface must be claimed by at '.
        'least one controller method, and every claim must name a surface that exists.'
    );

    // Every variant must point at a method that is itself covered — otherwise a
    // chain of variants can bottom out at nothing.
    // `toHaveKey($key, $second)` asserts the VALUE at that key — it takes no
    // message argument, same trap shape as Pest's variadic `toContain`. Assert a
    // plain boolean so the prose lands where it is read.
    foreach (IDOR_VERB_VARIANT_OF as $variant => [$covered, $reason]) {
        expect(array_key_exists($covered, IDOR_COVERED_BY))->toBeTrue(
            "{$variant} is declared a verb variant of {$covered}, but {$covered} is not in IDOR_COVERED_BY. ".
            'A variant chain that bottoms out at an unprobed method proves nothing.'
        );
        expect($reason)->not->toBe('',
            "{$variant} has an empty reason. An unexplained exclusion is indistinguishable from a gap."
        );
    }

    // Same rule for the unsafe-to-probe set. These are the entries most likely to
    // be added in a hurry to make the drift check go green, and an unexplained one
    // is indistinguishable from "I did not want to write a fixture".
    foreach (IDOR_UNSAFE_TO_PROBE as $action => $reason) {
        expect(trim($reason))->not->toBe('',
            "{$action} is declared unsafe to probe with no reason. Name the external side effect its 200 ".
            'control would fire, derived from source — not "probably outbound".'
        );
    }
});

it('leaves no stale map entry for a route that no longer exists', function () {
    // A controller method that was renamed or deleted leaves its map entry
    // behind, quietly asserting coverage for something that is gone.
    $live = array_map(static fn (array $r): string => $r['action'], idorPopulationRoutes());

    $declared = [
        ...array_keys(IDOR_COVERED_BY),
        ...array_keys(IDOR_VERB_VARIANT_OF),
        ...array_keys(IDOR_NOT_A_SURFACE),
        ...array_keys(IDOR_UNSAFE_TO_PROBE),
    ];

    $stale = array_values(array_diff($declared, $live));

    expect($stale)->toBe([], sprintf(
        "Classification entries for route(s) that are no longer in the population:\n  %s\n".
        'Remove the entry, and remove its probe from IDOR_SURFACES if it had one.',
        implode("\n  ", $stale),
    ));
});
