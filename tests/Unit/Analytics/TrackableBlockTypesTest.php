<?php

// tests/Unit/Analytics/TrackableBlockTypesTest.php
//
// #TEST-1 sub-item 4 — pure logic, no DB. TrackableBlockTypes is the single
// source of truth shared by the ingest writer and the dashboard read path
// (AnalyticsQueryService::topSections), so a normalization bug here (case,
// blank entries) would make the two sides silently disagree on what's trackable.

use App\Services\Analytics\TrackableBlockTypes;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// sectionTypes() reads config('partna.section_block_types', ...) — needs a
// booted app container.
uses(TestCase::class)->in(__FILE__);

// ─── sectionTypes() ─────────────────────────────────────────────────────────

it('returns the configured section block types, lowercased and trimmed', function () {
    Config::set('partna.section_block_types', [' Gallery ', 'SERVICES', 'booking']);

    expect(TrackableBlockTypes::sectionTypes())->toBe(['gallery', 'services', 'booking']);
});

it('filters out blank and non-string entries from the config list', function () {
    Config::set('partna.section_block_types', ['gallery', '', '   ', null, 123, false, 'services']);

    expect(TrackableBlockTypes::sectionTypes())->toBe(['gallery', 'services']);
});

it('falls back to the default section types when the config key is entirely absent', function () {
    // Config::offsetUnset()/set(..., null) both just SET the key to null under
    // the hood (Illuminate\Config\Repository::offsetUnset calls set($key, null))
    // — config('key', $default) only falls back to $default when the key path
    // doesn't resolve at all, and a null VALUE still resolves. Truly removing
    // the key means replacing the whole 'partna' subtree without it.
    $partnaConfig = config('partna');
    unset($partnaConfig['section_block_types']);
    Config::set('partna', $partnaConfig);

    expect(TrackableBlockTypes::sectionTypes())->toBe(['gallery', 'services', 'booking']);
});

// ─── isClickTrackable() ─────────────────────────────────────────────────────

it('is trackable for a links/link pair', function () {
    expect(TrackableBlockTypes::isClickTrackable('links', 'link'))->toBeTrue();
});

it('is trackable for a links/link pair regardless of case', function () {
    expect(TrackableBlockTypes::isClickTrackable('LINKS', 'Link'))->toBeTrue();
});

it('is trackable for a sections block whose type is in the configured allowlist', function () {
    Config::set('partna.section_block_types', ['gallery', 'services']);

    expect(TrackableBlockTypes::isClickTrackable('sections', 'gallery'))->toBeTrue();
});

it('is trackable for a sections block whose type is in the allowlist regardless of case', function () {
    Config::set('partna.section_block_types', ['gallery', 'services']);

    expect(TrackableBlockTypes::isClickTrackable('SECTIONS', 'Gallery'))->toBeTrue();
});

it('is not trackable for a sections block whose type is absent from the configured allowlist', function () {
    Config::set('partna.section_block_types', ['gallery', 'services']);

    expect(TrackableBlockTypes::isClickTrackable('sections', 'courses'))->toBeFalse();
});

it('is not trackable for a links block whose type is not "link"', function () {
    expect(TrackableBlockTypes::isClickTrackable('links', 'button'))->toBeFalse();
});

it('is not trackable for an unrecognized block group even with an allowlisted type', function () {
    Config::set('partna.section_block_types', ['gallery']);

    expect(TrackableBlockTypes::isClickTrackable('other', 'gallery'))->toBeFalse();
});

it('is not trackable when group or type is null', function () {
    expect(TrackableBlockTypes::isClickTrackable(null, null))->toBeFalse()
        ->and(TrackableBlockTypes::isClickTrackable('links', null))->toBeFalse()
        ->and(TrackableBlockTypes::isClickTrackable(null, 'link'))->toBeFalse();
});

it('respects a narrowed config override — a type removed from config stops being trackable', function () {
    Config::set('partna.section_block_types', ['services']);

    expect(TrackableBlockTypes::isClickTrackable('sections', 'gallery'))->toBeFalse()
        ->and(TrackableBlockTypes::isClickTrackable('sections', 'services'))->toBeTrue();
});
