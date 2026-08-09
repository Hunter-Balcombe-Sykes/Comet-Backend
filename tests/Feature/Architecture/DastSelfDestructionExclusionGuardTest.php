<?php

// Architecture test — every route that can revoke the DAST scanner's own
// credentials, delete its account, or mutate its auth state must be in
// scripts/dast/active/zap-context.yaml's excludePaths.
//
// WHY: bug 5 of 71f027b11. Once the active lane became genuinely authenticated,
// ZAP hit POST api/sessions/logout, POST api/sessions/logout-others and
// DELETE api/sessions/{sessionId} and revoked its OWN session mid-pass, so every
// later request 401'd — including the IDOR probes at the end of the plan. The fix
// added those paths to excludePaths, and zap-context.yaml then admitted in a
// comment that "nothing detects that class automatically". This is that detector.
//
// The class only became reachable when auth started working, so it keeps growing
// as the app does, and the symptom (a late-plan 401 cascade) reads like a broken
// token rather than a self-inflicted logout. The active lane is manual-only and
// takes ~12 minutes per run, so nothing else would catch a new one until someone
// spent that 12 minutes and then misdiagnosed the result.
//
// THE DETECTOR IS SERVICE DEPENDENCY, NOT NAMING. A `delete|revoke` regex over
// route names would flag half the API and get itself suppressed — CLAUDE.md's
// "add signals high-confidence only". Exactly three services in app/ revoke
// credentials, delete the account, or mutate auth state, and a controller that
// does any of those things has to reach one of them:
//
//   App\Services\Auth\TokenRevocationService  — revokes sessions (the literal
//                                                cause of bug 5)
//   App\Services\User\AccountDeletionService  — request/confirm/cancel deletion
//   App\Services\Auth\SupabaseAdminService    — admin auth writes, incl. MFA
//                                                factor removal
//
// That is what makes this a guard rather than a restatement of the exclusion
// list: a NEW controller that starts revoking sessions is caught by its
// dependency, before anyone has to remember to add its path.
//
// LIMIT OF THE DETECTOR, stated so nobody reads it as stronger than it is: the
// match is `str_contains` on the controller class's OWN source file, so it sees
// DIRECT references only. A controller that revokes through a new wrapper
// service, a dispatched job, or a trait is NOT caught. That the guarantee holds
// today is a property of the current graph — TokenRevocationService has exactly
// three consumers — not of the detector's construction. Adding an indirection
// layer means adding it to SELF_DESTRUCTION_SERVICES.
//
// The converse (a controller that only MENTIONS one of these services in a
// comment) would be a false positive, but it fails safe: the remedy is a
// harmless extra exclusion, not a suppressed guard. No controller does today.
//
// SCOPE — user-facing routes only. api/staff/*, api/public/*, api/internal/* and
// api/webhooks/* are outside the population, deliberately: StaffAccountDeletionController
// and StaffUserController do reach these services, but they act on ANOTHER
// professional's account, not the scanner's, and the scanner is not staff so it
// cannot reach them at all (they 401/403). "Self-destruction" means the scanner's
// own credentials. A staff route deleting someone else is a different concern and
// does not produce the 401 cascade this exists to prevent.
//
// SCOPE — non-GET only. GET api/sessions (the collection read) is deliberately
// IN scope for the scanner: it is harmless and a real surface. That is why
// excludePaths says `.*api/sessions/.*` with a trailing slash rather than
// `.*api/sessions.*`. The verb carve-out here mirrors that decision.
//
// NO SEPARATE CI JOB, deliberately. outbound-http-guard has one (ci.yml:553)
// because a green Feature suite is its gate and the suite can abort before
// reaching it — that guard protects a live SSRF control on the request path.
// This one protects a manual-only tool's configuration: the cost of missing it
// for one CI run is that a future active-lane run fails confusingly, not that
// production ships a hole. It runs in `composer test`, which is development's
// real gate. Revisit if the active lane ever becomes gating.

use App\Services\Auth\SupabaseAdminService;
use App\Services\Auth\TokenRevocationService;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * The services that revoke credentials, delete the account, or mutate auth state.
 * A controller reaching any of these is doing something the scanner must not do
 * to itself. Keep this list narrow — breadth here is what turns a guard into
 * noise, and a noisy guard gets suppressed.
 *
 * @var list<class-string>
 */
const SELF_DESTRUCTION_SERVICES = [
    TokenRevocationService::class,
    AccountDeletionService::class,
    SupabaseAdminService::class,
];

/** URI prefixes outside the scanner's own-credentials concern — see SCOPE above. */
const OUT_OF_POPULATION_PREFIXES = [
    'api/staff/',
    'api/public/',
    'api/internal/',
    'api/webhooks/',
];

function dastContextPath(): string
{
    return base_path('scripts/dast/active/zap-context.yaml');
}

function dastActiveScriptPath(): string
{
    return base_path('scripts/dast/active/zap-active.sh');
}

/**
 * excludePaths per context, keyed by context name.
 *
 * @return array<string, list<string>>
 */
function dastExcludePathsByContext(): array
{
    $parsed = Yaml::parseFile(dastContextPath());

    $out = [];
    foreach ($parsed['env']['contexts'] ?? [] as $context) {
        $out[$context['name']] = array_values($context['excludePaths'] ?? []);
    }

    return $out;
}

/**
 * The alternation terms in zap-active.sh's exclusion-verification grep.
 *
 * The grep restates zap-context.yaml's excludePaths as one pattern (the YAML
 * needs a regex per context, the grep needs one). There is intentionally no
 * shared source, which is exactly why the two need pinning against each other.
 *
 * @return list<string>
 */
function dastGrepAlternationTerms(): array
{
    $script = (string) file_get_contents(dastActiveScriptPath());

    // The optional trailing `/` after the group is matched, not required, and is
    // folded back onto every term. A shared suffix is the shape this grep used to
    // have, and it is a legitimate way to write it — it just has to produce the
    // same terms as excludePaths. Requiring the current shape here would report a
    // real drift as "the regex changed", which is the wrong failure message.
    if (preg_match('/grep -qE "api\/\(([^)]+)\)(\/?)"/', $script, $m) !== 1) {
        throw new RuntimeException(
            'Could not find the exclusion-verification grep in '.dastActiveScriptPath().
            '. If its shape changed, update this test rather than deleting the assertion.'
        );
    }

    $sharedSuffix = $m[2];

    return array_map(
        static fn (string $term): string => 'api/'.$term.$sharedSuffix,
        explode('|', $m[1]),
    );
}

/**
 * The core path an excludePaths regex targets: `.*api/sessions/.*` → `api/sessions/`.
 */
function dastExcludeCore(string $pattern): string
{
    return (string) preg_replace('/^\.\*|\.\*$/', '', $pattern);
}

/**
 * Every in-scope, non-GET route whose controller reaches a self-destruction service.
 *
 * @return list<array{uri: string, methods: list<string>, action: string}>
 */
function dastSelfDestructionRoutes(): array
{
    $sourceCache = [];
    $found = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        foreach (OUT_OF_POPULATION_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                continue 2;
            }
        }

        $writeMethods = array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']));
        if ($writeMethods === []) {
            continue;
        }

        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue; // closure route — no controller class to inspect
        }

        [$class] = explode('@', $action, 2);
        if (! class_exists($class)) {
            continue;
        }

        if (! array_key_exists($class, $sourceCache)) {
            $file = (new ReflectionClass($class))->getFileName();
            $sourceCache[$class] = $file === false ? '' : (string) file_get_contents($file);
        }

        foreach (SELF_DESTRUCTION_SERVICES as $service) {
            if (str_contains($sourceCache[$class], $service)) {
                $found[] = ['uri' => $uri, 'methods' => $writeMethods, 'action' => $action];

                continue 2;
            }
        }
    }

    return $found;
}

/** True when any of $patterns full-matches a representative URL for $uri (ZAP semantics). */
function dastIsExcluded(string $uri, array $patterns): bool
{
    $url = 'http://host.docker.internal:8100/'.$uri;

    foreach ($patterns as $pattern) {
        if (preg_match('#^'.$pattern.'$#', $url) === 1) {
            return true;
        }
    }

    return false;
}

it('finds the self-destruction routes it is meant to guard', function () {
    // A detector that matches nothing passes every assertion below vacuously.
    // Pin the floor: the three families bug 5 was about must all be present.
    $uris = array_map(static fn (array $r): string => $r['uri'], dastSelfDestructionRoutes());

    expect($uris)->toContain(
        'api/sessions/logout',
        'api/sessions/logout-others',
        'api/sessions/{sessionId}',
        'api/me/deletion/confirm',
        'api/account/mfa/factors/{factorId}',
    );
});

it('excludes every self-destruction route in every context', function () {
    $byContext = dastExcludePathsByContext();

    expect($byContext)->not->toBeEmpty();

    foreach (dastSelfDestructionRoutes() as $route) {
        foreach ($byContext as $context => $patterns) {
            expect(dastIsExcluded($route['uri'], $patterns))->toBeTrue(
                "{$route['action']} ({$route['uri']}) reaches a credential-revoking service but is NOT ".
                "excluded in ZAP context '{$context}'. An authenticated scanner will call it and log ".
                'itself out mid-pass; the symptom is a late-plan 401 cascade that reads like an expired '.
                'token. Add it to excludePaths in scripts/dast/active/zap-context.yaml (all three '.
                "contexts) and to zap-active.sh's exclusion-verification grep."
            );
        }
    }
});

it('keeps the three contexts exclusion lists identical', function () {
    $byContext = dastExcludePathsByContext();

    expect(array_keys($byContext))->toEqualCanonicalizing(['identity-a', 'identity-b', 'unauth']);

    $reference = $byContext['identity-a'];
    foreach ($byContext as $context => $patterns) {
        expect($patterns)->toEqualCanonicalizing(
            $reference,
            "ZAP context '{$context}' has a different excludePaths list from 'identity-a'. The three ".
            'lists are one contract stated three times; a divergence means one pass is scanning '.
            'something the others are not.'
        );
    }
});

it('keeps zap-context.yaml and the zap-active.sh grep in agreement, both ways', function () {
    // The YAML needs a regex per context; the grep needs one pattern. Same
    // contract, stated twice, with no shared source — so a drift is a silent
    // hole: the YAML stops ZAP requesting a path, the grep is what PROVES it
    // did not. An entry in only one of them means one half is unenforced.
    $yamlCores = array_map(dastExcludeCore(...), dastExcludePathsByContext()['identity-a']);
    $grepTerms = dastGrepAlternationTerms();

    sort($yamlCores);
    sort($grepTerms);

    expect($grepTerms)->toEqual(
        $yamlCores,
        "scripts/dast/active/zap-active.sh's exclusion-verification grep and zap-context.yaml's ".
        'excludePaths have drifted. Every excludePaths entry must have a matching alternation term '.
        'and vice versa — including its trailing slash, which is what decides whether the harmless '.
        'GET api/sessions collection read stays in scope.'
    );
});

it('leaves PATCH api/me and POST api/me/data-export in scope', function () {
    // Both are deliberately NOT excluded and must stay that way — this fires if
    // someone broadens an exclusion (e.g. `.*api/me/.*`) and silently drops a
    // high-value fuzz target out of the scan.
    //
    // PATCH api/me: UpdateUserRequest keeps `handle` out, so no Cloudflare KV write.
    // POST api/me/data-export: runs inline under QUEUE_CONNECTION=sync, but
    // MAIL_MAILER=log contains it — slow, not destructive.
    foreach (dastExcludePathsByContext() as $context => $patterns) {
        expect(dastIsExcluded('api/me', $patterns))->toBeFalse(
            "PATCH api/me is excluded in ZAP context '{$context}'. It is deliberately in scope."
        );
        expect(dastIsExcluded('api/me/data-export', $patterns))->toBeFalse(
            "POST api/me/data-export is excluded in ZAP context '{$context}'. It is deliberately in scope."
        );
    }
});
