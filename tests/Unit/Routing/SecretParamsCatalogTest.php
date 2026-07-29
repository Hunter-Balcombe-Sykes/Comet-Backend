<?php

use App\Routing\Rulepack;
use App\Routing\SecretParams;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * The mechanical link that stops a future detector adding a `?key=`-shaped
 * identity param the redactor would then silently eat. If this test goes
 * red, a new catalog definition introduced a query_requires param not yet
 * in SecretParams::IDENTITY_PARAMS — add it there, do not touch the segment
 * vocabulary.
 */
it('keeps every catalog query_requires param on the identity allowlist', function () {
    $identityParams = (new ReflectionClass(SecretParams::class))->getConstant('IDENTITY_PARAMS');

    $rulepack = Rulepack::fromCompiledCatalog();

    $catalogParams = [];
    foreach ($rulepack->detectors as $detector) {
        foreach ($detector['query_requires'] ?? [] as $param) {
            $catalogParams[] = $param;
        }
    }
    $catalogParams = array_unique($catalogParams);

    expect($catalogParams)->not->toBeEmpty();

    foreach ($catalogParams as $param) {
        expect($identityParams)->toContain($param);
    }
});
