<?php

use Illuminate\Support\Facades\DB;

// The read side of catalog.unmatched_domains. Without this the writer would
// have reproduced the exact defect it was built to fix: a populated table
// nothing consults.

beforeEach(function () {
    setupCatalogRuntimeTables();
});

function queueUnmatched(string $key, int $hits, bool $hasDetectors = false, ?string $triagedAt = null): void
{
    DB::connection('pgsql')->table('catalog.unmatched_domains')->insert([
        'registrable_key' => $key,
        'sample_path_shape' => '/book/*',
        'hits' => $hits,
        'has_detectors' => $hasDetectors,
        'first_seen_at' => now()->subDays(3)->toDateTimeString(),
        'last_seen_at' => now()->toDateTimeString(),
        'triaged_at' => $triagedAt,
    ]);
}

it('ranks the queue by how often a domain turns up', function () {
    // Frequency IS the prioritisation — the whole reason hits is a counter
    // rather than a flag. A list in insertion order would bury the platform
    // fifty users pasted under the one somebody tried once.
    queueUnmatched('rare.example', 1);
    queueUnmatched('everywhere.example', 97);

    $this->artisan('catalog:unmatched')
        ->expectsOutputToContain('everywhere.example')
        ->assertSuccessful();

    $ordered = DB::connection('pgsql')->table('catalog.unmatched_domains')
        ->orderByDesc('hits')->pluck('registrable_key')->all();
    expect($ordered[0])->toBe('everywhere.example');
});

it('hides domains already triaged', function () {
    // triaged_at is how the queue drains. Without it every run re-presents
    // work someone already decided about, and the list stops being read.
    queueUnmatched('already-handled.example', 50, false, now()->toDateTimeString());
    queueUnmatched('still-open.example', 2);

    $this->artisan('catalog:unmatched')
        ->expectsOutputToContain('still-open.example')
        ->doesntExpectOutputToContain('already-handled.example')
        ->assertSuccessful();
});

it('can show the triaged ones when asked', function () {
    queueUnmatched('already-handled.example', 50, false, now()->toDateTimeString());

    $this->artisan('catalog:unmatched', ['--all' => true])
        ->expectsOutputToContain('already-handled.example')
        ->assertSuccessful();
});

it('marks a domain triaged so it leaves the queue', function () {
    queueUnmatched('handled-now.example', 12);

    $this->artisan('catalog:unmatched', ['--triage' => 'handled-now.example'])->assertSuccessful();

    expect(DB::connection('pgsql')->table('catalog.unmatched_domains')
        ->where('registrable_key', 'handled-now.example')->value('triaged_at'))->not->toBeNull();
});

it('refuses to triage a domain that is not queued', function () {
    $this->artisan('catalog:unmatched', ['--triage' => 'never-seen.example'])->assertFailed();
});

it('says so plainly when the queue is empty', function () {
    $this->artisan('catalog:unmatched')
        ->expectsOutputToContain('No untriaged')
        ->assertSuccessful();
});
