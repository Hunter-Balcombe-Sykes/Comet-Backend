<?php

// EXIT CRITERION for the platform-integrations registry redesign (Plan 5):
// no platform reads its stored connection payload via untyped data_get, and every
// bespoke/special read path goes through a typed Payload DTO. Two allowlist entries
// are documented and justified inline.

// Files whose generic, platform-agnostic plumbing legitimately reads payloads
// dynamically (caller-supplied field / shape closure) — cannot resolve to one DTO.
const PAYLOAD_PLUMBING_ALLOWLIST = [
    'app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php',
];

// Refresh/WRITE-path files: Strategies/Fetch/ is an accepted, documented exemption.
// Each FetchStrategy (YoutubeFetch, VimeoFetch, OEmbedFetch, etc.) reads
// $connection->payload as a raw array to extract the stored handle/config it needs
// to re-pull fresh data from upstream. This is the WRITE side of the refresh path —
// the payload is an INPUT to a re-fetch, not a read boundary exposed to API consumers.
// The output of each fetch is parity-tested byte-for-byte against the legacy refresher
// (PlatformRefresher), so the raw reads are deliberate and stable.
// FeedPayload/EmbedPayload keys *could* be consumed via typed DTOs here, but the
// marginal tidiness doesn't justify touching the parity-sensitive refresh path.
// Accepted exemption — do NOT migrate without a paired parity-test update.
function isDeferredRefreshPath(string $path): bool
{
    return str_contains($path, 'app/Services/Platforms/Strategies/Fetch/')
        || str_ends_with($path, 'app/Services/Platforms/PlatformRefresher.php')
        // DTOs themselves legitimately index $payload['key'] in fromArray().
        || str_contains($path, 'app/Services/Platforms/Payloads/');
}

/** @return list<string> every *.php under the given app dirs */
function platformSurfaceFiles(): array
{
    $roots = [
        base_path('app/Http/Controllers/Api/Platforms'),
        base_path('app/Jobs/Platforms'),
        base_path('app/Observers/Core'),
        base_path('app/Services/Platforms'),
    ];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('has no untyped data_get on a stored connection payload (outside generic plumbing)', function () {
    $offenders = [];

    foreach (platformSurfaceFiles() as $path) {
        $rel = str_replace(base_path().'/', '', $path);
        if (isDeferredRefreshPath($path)) {
            continue;
        }
        $lines = file($path);
        foreach ($lines as $n => $line) {
            // data_get( … payload … ) — the literal exit criterion.
            if (preg_match('/data_get\([^;]*payload/', $line)) {
                if (in_array($rel, PAYLOAD_PLUMBING_ALLOWLIST, true)) {
                    continue;
                }
                $offenders[] = "{$rel}:".($n + 1).'  '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "Untyped data_get on a payload survives — migrate onto a typed DTO:\n".implode("\n", $offenders));
});

it('has no raw stored-payload access in the migrated read-path files (DTO-mediated only)', function () {
    // The exact files Plan 5 migrated. Each must read its payload only via a
    // …Payload::fromArray(...) DTO — no `->payload[`, no is_array($x->payload),
    // no `$x->payload ?? []`.
    $readPathFiles = [
        'app/Http/Controllers/Api/Platforms/InstagramController.php',
        'app/Http/Controllers/Api/Platforms/GoogleBusinessController.php',
        'app/Http/Controllers/Api/Platforms/EventsPlatformController.php',
        'app/Http/Controllers/Api/Platforms/BookingController.php',
        'app/Http/Controllers/Api/Platforms/ReservationsController.php',
        'app/Http/Controllers/Api/Platforms/OnlineOrderingController.php',
        'app/Http/Controllers/Api/Platforms/CustomLinksController.php',
        'app/Jobs/Platforms/InstagramConnectJob.php',
        'app/Jobs/Platforms/GoogleBusinessEnrichJob.php',
        'app/Observers/Core/IntegrationConnectionObserver.php',
        'app/Services/Platforms/EventsCatalog.php',
        'app/Services/Platforms/MenuSource.php',
        'app/Services/Platforms/GoogleBusinessAutoSync.php',
    ];

    $offenders = [];
    foreach ($readPathFiles as $rel) {
        $path = base_path($rel);
        expect(is_file($path))->toBeTrue("Expected migrated file to exist: {$rel}");
        foreach (file($path) as $n => $line) {
            // Skip comment lines — the guard targets live code, not historical comments
            // (e.g. GoogleBusinessEnrichJob retains a "// before: …" migration note).
            if (preg_match('/^\s*\/\//', $line)) {
                continue;
            }
            $isRawAccess = preg_match('/\$\w+(\?)?->payload\s*\[/', $line)            // ->payload['key']
                || preg_match('/is_array\(\s*\$\w+(\?)?->payload\s*\)/', $line)        // is_array($x->payload)
                || preg_match('/->payload\s*\?\?\s*\[\]/', $line)                      // $x->payload ?? []
                || preg_match('/data_get\([^;]*payload/', $line);                      // data_get(... payload ...)
            if ($isRawAccess) {
                $offenders[] = "{$rel}:".($n + 1).'  '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "Raw stored-payload access survives in a migrated read path:\n".implode("\n", $offenders));
});
