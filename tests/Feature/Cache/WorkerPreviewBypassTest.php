<?php

/*
|--------------------------------------------------------------------------
| Worker preview-bypass guard
|--------------------------------------------------------------------------
| The dashboard live preview appends ?preview=1 and depends on serveIndividual()
| routing it straight to origin — no cache read, no cache write. cloudflare-worker/
| has no JS test harness, so this PHP test parses the source, exactly as
| ReservedSubdomainWorkerSyncTest does for the RESERVED set. Losing the param
| from the bypass would silently return the preview to a 24h-TTL edge entry and
| reintroduce the symptom this whole change set exists to remove.
*/

it('keeps preview in the serveIndividual bypass condition', function () {
    $path = base_path('cloudflare-worker/src/index.js');
    $contents = file_get_contents($path);

    expect($contents)->not->toBeFalse("Could not read {$path}");

    // Match the bypass condition itself, not just the word "preview" anywhere in
    // the file — a comment mentioning preview must not satisfy this guard.
    $matched = preg_match(
        '/if\s*\(\s*previewParams\.has\((.*?)\)\s*\)\s*\{/s',
        $contents,
        $match
    );

    expect($matched)->toBe(1,
        'Could not locate the `if (previewParams.has(...))` bypass in '
        .'cloudflare-worker/src/index.js — has serveIndividual() been restructured? '
        .'Update this guard to match.'
    );

    preg_match_all('/previewParams\.has\("([^"]+)"\)/', $contents, $params);

    expect($params[1])->toContain('preview')
        ->and($params[1])->toContain('architecture')
        ->and($params[1])->toContain('skeleton');
});
