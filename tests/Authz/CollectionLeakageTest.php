<?php

use App\Models\Core\Site\Page;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\Site;
use Tests\Authz\AuthzTestCase;
use Tests\Authz\Fixtures;
use Tests\Authz\Matrix;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

uses(AuthzTestCase::class);

/**
 * The blind spot CrossTenantTest structurally cannot cover.
 *
 * That suite works by substituting identity B's id INTO a path, so it can only
 * reach routes carrying a {param}. A collection endpoint has no id to
 * substitute — and asks a different question: not "can A address B's row?" but
 * "does A's own listing contain B's rows?". `route:list` puts 132 param-free
 * GET routes in the user + platforms groups and none were swept for foreign ids
 * before this file.
 *
 * WHAT THIS DOES **NOT** CLAIM. It does not close a pages/sections isolation
 * gap: `tests/Feature/Security/TenantIsolation/SectionsAndContentIsolationTest`
 * has covered that since the curation plan landed ("never lists another user's
 * pages or sections"), and soundly — it carries its own positive control. A
 * "no cross-tenant test for the pages and sections index endpoints" finding does
 * exist in that sweep's `.drafts/`, but drafts are untracked pre-adjudication
 * output: it never reached CONSOLIDATED.md, which instead records this exact
 * class as **"Phantom coverage gaps … the lens cannot see
 * tests/Feature/Security/PolicyEnforcement/ or TenantIsolation/"**. Verified
 * 2026-07-30 before writing this file.
 *
 * So the contribution is narrower than "index isolation": the SWEEP below, over
 * routes nobody has hand-written a test for.
 *
 * WHY THE FIRST TEST EXISTS ANYWAY — it is the sweep's NON-VACUITY GUARD, not a
 * second isolation claim. Fixtures::seedOwnedBy() runs for identity B only, so A
 * owns a bare site: measured against the real route table, only 4 of the 132
 * responses contain a uuid at all, and `api/site/pages`, `api/site/sections` and
 * `api/platforms/events/selection` return {"pages":[]}, {"sections":[]} and
 * {"selection":null} for A. "B's id is absent" is trivially true of an empty
 * body. Without a route where detection is PROVEN to fire, the sweep would stay
 * green if every endpoint started 500ing — the false-PASS class this harness's
 * own spec warns about. Mutation-checked 2026-07-30: pointing the not-contains
 * assertion at an id that IS present fails the test.
 */
it('detects a foreign id in a collection response (sweep non-vacuity guard)', function () {
    Fixtures::ensureSeeded();

    $a = Fixtures::identityA();
    $site = Site::query()->where('user_id', $a->id)->firstOrFail();

    // Identity A's OWN rows, created inside this test's transaction. Visible to
    // the request (same connection) and rolled back with it, so this does not
    // disturb the committed fixtures the other suites substitute from.
    $page = new Page;
    $page->key = 'authz-a-page';
    $page->label = 'Authz A';
    $page->site()->associate($site);
    $page->save();

    $section = new Section;
    $section->kind = 'richtext';
    $section->page()->associate($page);
    $section->site()->associate($site);
    $section->save();

    $cases = [
        'api/site/pages' => [(string) $page->id, (string) Fixtures::idFor(Page::class)],
        'api/site/sections' => [(string) $section->id, (string) Fixtures::idFor(Section::class)],
    ];

    foreach ($cases as $uri => [$ownId, $foreignId]) {
        $response = Matrix::isolated(fn () => actingAsUser($a)->json('GET', '/'.$uri));
        $body = (string) $response->getContent();

        // POSITIVE CONTROL. If A's own row is missing, the endpoint returned an
        // empty listing and the isolation assertion below would pass vacuously.
        //
        // PHPUnit assertions, not expect()->toContain(): Pest's string
        // toContain() is VARIADIC over needles, so a second argument is taken as
        // another needle to find rather than a failure message. These carry the
        // message, which is the whole point of the row above.
        $this->assertStringContainsString($ownId, $body, sprintf(
            "GET %s did not return identity A's own row %s (status %d).\n"
            ."Without it the isolation assertion that follows is vacuous — an empty\n"
            .'listing contains no foreign ids either. Body: %s',
            $uri,
            $ownId,
            $response->getStatusCode(),
            substr($body, 0, 300),
        ));

        // THE INVARIANT.
        $this->assertStringNotContainsString($foreignId, $body, sprintf(
            "AUTHZ GET %s\n  identity B's row %s appeared in identity A's listing.\n"
            ."  This is a cross-tenant read: the endpoint is not scoped to the caller.\n"
            .'  Body: %s',
            $uri,
            $foreignId,
            substr($body, 0, 300),
        ));
    }
});

/**
 * Regression net over every param-free GET in the user + platforms groups.
 *
 * This is the file's actual contribution, and its strength is declared honestly
 * rather than implied: it asserts only that no identity-B id appears, and 128 of
 * the 132 routes return a body with no ids in it at all, so for those the
 * assertion is a tripwire rather than a proof. It bites when a future controller
 * starts returning unscoped rows on a route nobody hand-wrote a test for — which
 * is most of them — and costs ~0.5s.
 *
 * It is NOT evidence that those 132 routes are correctly scoped today. Anyone
 * reading a green run should take exactly that much from it.
 */
it('never leaks an identity B row id into any param-free collection response', function () {
    Fixtures::ensureSeeded();

    $bIds = [];
    foreach (Fixtures::seededModels() as $model) {
        if ($id = Fixtures::idFor($model)) {
            $bIds[(string) $id] = class_basename($model);
        }
    }

    $cases = collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => in_array($c->group(), ['user', 'platforms'], true))
        ->reject(fn (RouteCase $c) => $c->hasParams())
        ->filter(fn (RouteCase $c) => $c->method === 'GET')
        ->values();

    $failures = [];

    foreach ($cases as $case) {
        $response = Matrix::isolated(fn () => actingAsUser(Fixtures::identityA())
            ->json('GET', '/'.$case->uri));

        $body = (string) $response->getContent();

        foreach ($bIds as $id => $label) {
            if (str_contains($body, $id)) {
                $failures[] = sprintf(
                    "AUTHZ GET %s\n  observed: %d\n  leaked:   identity B's %s (%s)\n"
                    ."  reason:   a collection endpoint returned another tenant's row; scope the query to the caller\n"
                    .'  fix:      the controller, not this test',
                    $case->uri,
                    $response->getStatusCode(),
                    $label,
                    $id,
                );
            }
        }
    }

    if ($failures !== []) {
        $this->fail(Matrix::report($failures, $cases->count(), 'Collection leakage sweep'));
    }

    expect($failures)->toBe([]);
});
