<?php

// Tier-S runtime property tests for App\Ingest\Manifest\Manifest and
// StreamSpec (plan §4/§22): host admission, claim-scoped redaction, and the
// may-delete truth table. Pure PHP value objects — no database, no Laravel
// bootstrap.

use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;

/**
 * @param  list<string>  $hosts
 * @param  list<string>  $redactions
 * @param  array<string, string>  $redactionScopes
 */
function testIngestManifest(array $hosts = [], array $redactions = [], array $redactionScopes = []): Manifest
{
    return new Manifest(
        source: SourceKey::of('bandcamp'),
        identifierKind: 'handle',
        hosts: $hosts,
        streams: [],
        redactions: $redactions,
        redactionScopes: $redactionScopes,
    );
}

// ── mayContact() host admission ─────────────────────────────────────────────

it('allows contacting a host that matches an exact entry in the manifest', function () {
    $manifest = testIngestManifest(['bandcamp.com']);

    expect($manifest->mayContact('bandcamp.com'))->toBeTrue();
});

it('allows contacting a host that matches a wildcard subdomain glob', function () {
    $manifest = testIngestManifest(['*.bandcamp.com']);

    expect($manifest->mayContact('artist.bandcamp.com'))->toBeTrue();
});

it('matches hosts case-insensitively', function () {
    $manifest = testIngestManifest(['Bandcamp.com']);

    expect($manifest->mayContact('BANDCAMP.COM'))->toBeTrue();
});

it('refuses a host that is not declared in the manifest — a derived URL can never become an unbounded fetch elsewhere', function () {
    $manifest = testIngestManifest(['bandcamp.com']);

    expect($manifest->mayContact('evil.example.com'))->toBeFalse();
});

// ── redactionsFor(isClaimed) — the live-regression guard from the plan ──────

it('returns "always" redactions for both claimed and unclaimed accounts', function () {
    $manifest = testIngestManifest(redactions: ['ip_address'], redactionScopes: ['ip_address' => 'always']);

    expect($manifest->redactionsFor(true))->toBe(['ip_address']);
    expect($manifest->redactionsFor(false))->toBe(['ip_address']);
});

it('returns "when_unclaimed" redactions only for unclaimed accounts — claimed accounts keep the fuller record', function () {
    $manifest = testIngestManifest(redactions: ['contact_email'], redactionScopes: ['contact_email' => 'when_unclaimed']);

    expect($manifest->redactionsFor(false))->toBe(['contact_email']);
    expect($manifest->redactionsFor(true))->toBe([]);
});

it('defaults a redaction path with no declared scope to "always"', function () {
    $manifest = testIngestManifest(redactions: ['secret_field'], redactionScopes: []);

    expect($manifest->redactionsFor(true))->toBe(['secret_field']);
    expect($manifest->redactionsFor(false))->toBe(['secret_field']);
});

// ── StreamSpec::mayDelete() truth table ─────────────────────────────────────

it('may-delete truth table: only a non-Sample profile with a declared order field may ever delete', function (SourceProfile $profile) {
    $withOrderField = new StreamSpec(name: 's', target: 't', profile: $profile, orderField: 'seq');
    $withoutOrderField = new StreamSpec(name: 's', target: 't', profile: $profile, orderField: null);

    // Sample streams never delete no matter what; every other profile needs
    // BOTH the profile's own allowance AND a declared order field to reason
    // about coverage with — missing either one means absence can never be
    // trusted to mean deletion.
    expect($withOrderField->mayDelete())->toBe($profile !== SourceProfile::Sample);
    expect($withoutOrderField->mayDelete())->toBeFalse();
})->with(SourceProfile::cases());
