<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Regression coverage for expanding the trackable-section-type allowlist that
// the dashboard's `top_sections` JSON aggregate honours. The ingest controller
// already reads `partna.section_block_types` from config; this test pins the
// expected member list and asserts the click controller writes a row when the
// section block is `bio` or `documents` (previously rejected by a hardcoded
// 4-type filter in UserAnalyticsController). It also pins the
// section_block_types config contents against accidental shrinkage.

beforeEach(function (): void {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupLinkClicksTable();
});

it('exposes bio + documents in the section_block_types config', function (): void {
    $types = collect(config('partna.section_block_types', []))
        ->map(fn ($t) => strtolower((string) $t))
        ->values()
        ->all();

    expect($types)->toContain('bio', 'documents', 'gallery', 'services', 'booking');
});

it('records a click on a bio section block', function (): void {
    $tenant = createBrandTenant('expanded-bio');
    $block = createLinkBlockFor($tenant, [
        'block_group' => 'sections',
        'block_type' => 'bio',
        'title' => null,
        'url' => null,
    ]);

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
        'Origin' => 'https://expanded-bio.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'subdomain' => 'expanded-bio',
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->where('link_block_id', $block->id)->count())->toBe(1);
});

it('records a click on a documents section block', function (): void {
    $tenant = createBrandTenant('expanded-documents');
    $block = createLinkBlockFor($tenant, [
        'block_group' => 'sections',
        'block_type' => 'documents',
        'title' => null,
        'url' => null,
    ]);

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
        'Origin' => 'https://expanded-documents.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'subdomain' => 'expanded-documents',
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->where('link_block_id', $block->id)->count())->toBe(1);
});

it('accepts a click on a non-allowlisted section type (201) but writes no row — writer drops', function (): void {
    // Full-decouple: trackability is no longer checked synchronously. A click to a section
    // block whose type is not in config('partna.section_block_types') is accepted (201) and
    // dropped by PostgresEventWriter. The invariant — no row for an untrackable block — is
    // preserved at the writer.
    $tenant = createBrandTenant('expanded-rejected');
    $block = createLinkBlockFor($tenant, [
        'block_group' => 'sections',
        'block_type' => 'totally_invented_type',
        'title' => null,
        'url' => null,
    ]);

    $response = $this->withHeader('Origin', 'https://expanded-rejected.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/clicks', [
            'subdomain' => 'expanded-rejected',
            'block_id' => $block->id,
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});
