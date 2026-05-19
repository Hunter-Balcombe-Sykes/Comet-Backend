<?php

// §51 — Architecture test: AccountTypeTransitionService must not call
// ::dispatchSync() inside its DB::transaction() closure.
//
// Cloudflare HTTP I/O under a row lock starves the connection pool (audit SCALE-2).
// This test parses the service source file with string analysis to enforce the rule.

it('AccountTypeTransitionService has no ::dispatchSync() inside the DB::transaction closure', function () {
    $path = base_path('app/Services/Accounts/AccountTypeTransitionService.php');
    expect(file_exists($path))->toBeTrue("Service file missing: {$path}");

    $source = file_get_contents($path);

    // Locate the transaction closure opening.
    $transactionOpenPos = strpos($source, 'DB::transaction(function');
    expect($transactionOpenPos)->not->toBeFalse('DB::transaction(function not found in service');

    // Find the matching closing brace for the transaction closure.
    // We scan from the opening parenthesis of DB::transaction( and track
    // brace depth to find where the closure ends.
    $searchFrom = $transactionOpenPos;
    $openBrace = strpos($source, '{', $searchFrom);
    expect($openBrace)->not->toBeFalse('Opening brace of DB::transaction closure not found');

    $depth = 0;
    $closePos = null;
    for ($i = $openBrace; $i < strlen($source); $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $closePos = $i;
                break;
            }
        }
    }

    expect($closePos)->not->toBeNull('Could not find closing brace of DB::transaction closure');

    $closureBody = substr($source, $openBrace, $closePos - $openBrace + 1);

    expect($closureBody)->not->toContain(
        '::dispatchSync(',
        'dispatchSync() found inside DB::transaction closure — violates SCALE-2 audit finding'
    );
});
