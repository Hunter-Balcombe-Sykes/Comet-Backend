<?php

// TEST-3: IndividualProfileResource had zero test coverage. The audit's
// worked example proposed asserting the ABSENCE of primary_email/admin_notes
// — but IndividualProfileResource is built ENTIRELY from the $sections array
// passed to its constructor (see toArray() below); it never reads
// `$this->primary_email` (or any other User attribute besides handle,
// display_name, account_type) at all. An absence assertion on a field the
// resource has no code path to ever emit would pass trivially and PERMANENTLY
// — including on the day someone adds a PII field to $sections.
//
// Instead: full recursive key-set EQUALITY, including the nested `profile`
// sub-array's own key list. Any future field added to (or removed from, or
// renamed in) $sections' output breaks this test regardless of whether
// anyone thought about PII when they added it — forcing a human to look at
// the diff rather than silently shipping it.

use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Models\Core\User\User;
use Illuminate\Http\Request;

it('emits exactly the documented top-level and profile-nested key set', function () {
    $pro = new User;
    $pro->setRawAttributes([
        'id' => 'pro-1',
        'handle' => 'evo',
        'display_name' => 'Evo',
        'account_type' => 'partna',
    ]);

    // Every section populated (non-empty) so the object-coercion branches
    // (designKit/publicConfig/siteImages) don't collapse to stdClass and mask
    // a key that would only appear when non-empty.
    $sections = [
        'site_id' => 'site-1',
        'design_kit' => ['color_accent' => '#ffffff'],
        'design_media' => [['id' => 'm1']],
        'architecture_id' => 'staple',
        'public_config' => ['analyticsEndpoint' => 'https://analytics.example'],
        'page_order' => ['about'],
        'ranked_actions' => [['kind' => 'link', 'ref' => 'r1']],
        'ordering' => ['smartPageOrder' => true],
        'gallery' => [['id' => 'g1']],
        'curatedGallery' => [['id' => 'c1']],
        'links' => [['id' => 'l1']],
        'services' => [['id' => 's1']],
        'document' => ['title' => 'doc'],
        'newsletter' => ['title' => 'nl'],
        'contact' => ['email' => 'a@example.com'],
        'publicContact' => ['email' => 'a@example.com', 'phone' => null],
        'workplace' => ['name' => 'w1'],
        'site_images' => ['logoFull' => ['url' => 'https://cdn.example.com/logo.webp']],
        'policies' => ['privacy' => ['mode' => 'auto'], 'terms' => ['mode' => 'auto']],
    ];

    $array = (new IndividualProfileResource($pro, $sections))->resolve(Request::create('/'));

    expect(array_keys($array))->toBe([
        'profile', 'pageOrder', 'popularity', 'rankedActions', 'ordering',
        'designKit', 'designMedia', 'architectureId',
        'publicConfig', 'siteImages', 'policies',
    ]);

    expect(array_keys($array['profile']))->toBe([
        'handle', 'displayName', 'accountType', 'site_id',
        'gallery', 'curatedGallery', 'links', 'pools', 'services',
        'document', 'newsletter', 'contact', 'publicContact', 'workplace',
    ]);

    // (The skeletonId transition alias was dropped 2026-08-05 — apps/pages
    // reads architectureId first and had no remaining fallback traffic; this
    // key-set snapshot made that removal the visible, deliberate change the
    // alias's own comment asked for.)

    // No PII leaks through — confirms the allowlist shape, though the real
    // guarantee here is the key-set equality above: PII has no code path in
    // to begin with, so this assertion is a belt (the key-set is the braces).
    expect($array)->not->toHaveKey('primary_email');
    expect($array['profile'] ?? [])->not->toHaveKey('primary_email');
});

it('coerces empty designKit/publicConfig/siteImages sections to an object, not an array, in JSON', function () {
    $pro = new User;
    $pro->setRawAttributes([
        'id' => 'pro-2',
        'handle' => 'empty',
        'display_name' => 'Empty',
        'account_type' => 'partna',
    ]);

    // No design_kit/public_config/site_images keys supplied at all — the
    // resource's own `?? []` defaults kick in, so this also proves the
    // no-sections-given path shares the same key set as the fully-populated one.
    $array = (new IndividualProfileResource($pro, []))->resolve(Request::create('/'));

    expect(array_keys($array))->toBe([
        'profile', 'pageOrder', 'popularity', 'rankedActions', 'ordering',
        'designKit', 'designMedia', 'architectureId',
        'publicConfig', 'siteImages', 'policies',
    ]);

    // PHP's json_encode emits `{}` for stdClass and `[]` for an empty array —
    // this is the wire-shape contract the class docblock promises.
    $json = json_encode($array);
    expect($json)->toContain('"designKit":{}');
    expect($json)->toContain('"publicConfig":{}');
    expect($json)->toContain('"siteImages":{}');
    // The pools map serializes as an object even when no pool has a selection.
    expect($json)->toContain('"pools":{}');
});
