<?php

use App\Ingest\ConnectorRegistry;

// The registry is the ONLY path from a source_key to a connector class —
// RunSourceJob and the dispatcher resolve through it. P3 shipped 9 connector
// classes but registered 4, so five connectors existed, passed their own
// tests, and could never run. These assertions walk the directory so the MAP
// cannot drift from disk again in either direction.

function connectorClassesOnDisk(): array
{
    $files = glob(__DIR__.'/../../../app/Ingest/Connectors/*.php');

    return array_map(
        fn (string $file) => 'App\\Ingest\\Connectors\\'.basename($file, '.php'),
        $files,
    );
}

it('registers every connector class on disk', function () {
    $classes = connectorClassesOnDisk();
    expect($classes)->not->toBeEmpty();

    $registered = array_values(ConnectorRegistry::all());
    $missing = array_values(array_diff($classes, $registered));

    expect($missing)->toBe([], count($missing).' connector class(es) exist but are unreachable via ConnectorRegistry::MAP: '.implode(', ', $missing));
});

it('registers nothing that does not exist on disk', function () {
    $classes = connectorClassesOnDisk();

    $ghosts = array_values(array_diff(array_values(ConnectorRegistry::all()), $classes));

    expect($ghosts)->toBe([], 'ConnectorRegistry::MAP names class(es) with no file: '.implode(', ', $ghosts));
});

it('keys every connector under its own manifest source key', function () {
    foreach (ConnectorRegistry::all() as $key => $class) {
        $manifestKey = $class::manifest()->source->value;

        expect($key)->toBe($manifestKey, "{$class} is registered as '{$key}' but its manifest says '{$manifestKey}' — the dispatcher would never find it");
        expect(ConnectorRegistry::has($key))->toBeTrue();
        expect(ConnectorRegistry::manifestFor($key)->source->value)->toBe($key);
    }
});
