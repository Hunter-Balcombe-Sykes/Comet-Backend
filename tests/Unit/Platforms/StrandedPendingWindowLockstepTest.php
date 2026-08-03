<?php

use App\Services\Platforms\StrandedPendingWindow;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Drift guard for R2: StrandedPendingWindow::MINUTES is the single home for
// the "worker likely died" window read by the connect poll
// (DefersBespokeConnect, GenericPlatformController, ShopController), the
// refresh poll (RefreshController) and the backlog alarm
// (CheckPlatformRefreshBacklogCommand). This test does not assert HOW each
// site compares against the cutoff — DefersBespokeConnect/
// GenericPlatformController/ShopController are exclusive (`lt`),
// RefreshController is inclusive (`gt`) — only that none of them reintroduce
// a local `subMinutes(5)` literal or a private *_PENDING_MINUTES const, which
// is the whole point of giving the number one home.

it('MINUTES matches the published frontend contract', function () {
    // docs/frontend-contracts/2026-07-23-platform-connect-async.md: "a pending
    // row untouched for more than 5 minutes reports failed". Changing this
    // number changes a published API contract — must be a deliberate act,
    // not a drive-by edit.
    expect(StrandedPendingWindow::MINUTES)->toBe(5);
});

it('no consumer keeps a local copy of the stale-pending literal or const', function () {
    foreach ([
        'Http/Controllers/Api/Platforms/Concerns/DefersBespokeConnect.php',
        'Http/Controllers/Api/Platforms/GenericPlatformController.php',
        'Http/Controllers/Api/Platforms/RefreshController.php',
        'Http/Controllers/Api/Platforms/ShopController.php',
        'Http/Controllers/Api/Platforms/InstagramController.php',
        'Console/Commands/CheckPlatformRefreshBacklogCommand.php',
    ] as $relative) {
        $path = app_path($relative);
        expect(file_exists($path))->toBeTrue("Expected source file [$relative] to exist at $path.");

        $source = (string) file_get_contents($path);

        // Same COV-GUARD-1 trap as ConnectErrorSentenceLockstepTest: the message
        // argument was silently a second needle, so this never failed.
        $this->assertStringNotContainsString(
            'subMinutes(5)',
            $source,
            "[$relative] hand-types the stale-pending literal again; read StrandedPendingWindow::MINUTES instead."
        );
        expect($source)->not->toMatch('/PENDING_MINUTES\s*=/', "[$relative] reintroduces a local *_PENDING_MINUTES const; read StrandedPendingWindow::MINUTES instead.");
    }
});

// Positive control (COV-GUARD-1), mirroring ConnectErrorSentenceLockstepTest's.
it('proves the lockstep guard can fail: a hand-typed literal IS caught', function () {
    $forged = '$deadline = now()->subMinutes(5);';

    expect(fn () => $this->assertStringNotContainsString(
        'subMinutes(5)',
        $forged,
        'probe'
    ))->toThrow(ExpectationFailedException::class);
});
