<?php

use App\Services\Moderation\ModerationActionDispatcher;

/*
|--------------------------------------------------------------------------
| suspend_site <-> KV/edge-cache retirement architecture guard (EDGE-103)
|--------------------------------------------------------------------------
| ACTIONS_BY_DECISION pairs `suspend_site` with `sync_subdomain_kv` BY HAND
| for every decision type that suspends a site today (hide_site, suspend_user,
| ban_user, csam_auto_suspend). SuspendSiteJob does a query-builder MASS
| update (Site::query()->where(...)->update(...)) which fires NO Eloquent
| events, so SiteObserver::saved() never runs and nothing auto-retires the KV
| entry — the pairing is convention, not an enforced invariant. A 5th decision
| type added later that suspends a site while forgetting the KV/edge action
| would leave that site live at the edge with no automated signal.
|
| This sweeps the REAL constant via reflection (mirrors
| tests/Feature/Security/PolicyCoverageTest.php's allowlist-sweep pattern),
| so it automatically covers a decision type nobody has written yet — unlike
| ModerationActionDispatcherTest.php's hand-written per-decision assertions,
| which only prove today's 4 types are wired correctly and say nothing about
| a type added tomorrow.
|
| purge_cloudflare_cache also satisfies the pairing: PurgeModerationCacheJob
| (app/Jobs/Moderation/PurgeModerationCacheJob.php) is the SAME job class for
| both action types and unconditionally dispatches SyncSubdomainToKvJob
| regardless of which action type triggered it — so either one retires KV.
*/

it('every ACTIONS_BY_DECISION entry pairing suspend_site also retires KV/edge routing (EDGE-103)', function () {
    $actionsByDecision = (new ReflectionClass(ModerationActionDispatcher::class))
        ->getConstant('ACTIONS_BY_DECISION');

    expect($actionsByDecision)->toBeArray()->not->toBeEmpty();

    $offenders = [];
    foreach ($actionsByDecision as $decisionType => $actions) {
        $suspendsSite = in_array('suspend_site', $actions, true);
        $retiresRouting = in_array('sync_subdomain_kv', $actions, true)
            || in_array('purge_cloudflare_cache', $actions, true);

        if ($suspendsSite && ! $retiresRouting) {
            $offenders[] = $decisionType;
        }
    }

    expect($offenders)->toBe(
        [],
        'Decision type(s) suspend a site but never retire KV/edge routing: '.implode(', ', $offenders)
        ."\nEvery ACTIONS_BY_DECISION entry containing 'suspend_site' must also contain "
        ."'sync_subdomain_kv' or 'purge_cloudflare_cache' (see ModerationActionDispatcher::ACTIONS_BY_DECISION, EDGE-103)."
    );
});
