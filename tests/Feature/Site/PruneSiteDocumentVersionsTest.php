<?php

use App\Console\Commands\PruneSiteDocumentVersionsCommand;
use App\Site\Documents\BuildState;
use App\Site\Documents\DocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #PRIV-4: site:prune-document-versions thins site.site_documents to the
// newest N versions per (site_id, channel), never touching anything built
// within the minimum-age window and never deleting the newest version of a
// pair. DocumentBuilder::build()'s ->orderByDesc('version')->first() is the
// ONLY reader — every version this command deletes has zero consumers.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSectionsTables();
    setupContentTables();
});

// buildTestSite() lives in tests/Pest.php (shared with DocumentBuilderTest).

function seedDocumentVersion(string $siteId, string $channel, int $version, string $builtAt): void
{
    DB::table('site.site_documents')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'version' => $version,
        'channel' => $channel,
        'document' => '{}',
        'content_hash' => sha1((string) $version),
        'builder_revision' => 1,
        'warnings' => '[]',
        'built_at' => $builtAt,
    ]);
}

function documentVersions(string $siteId, string $channel = 'live'): array
{
    return DB::table('site.site_documents')
        ->where('site_id', $siteId)
        ->where('channel', $channel)
        ->orderBy('version')
        ->pluck('version')
        ->all();
}

it('keeps exactly the 3 newest versions when 10 exist, all old enough to prune', function () {
    [, $siteId] = buildTestSite();
    $old = now()->subDays(30)->toDateTimeString();
    for ($v = 1; $v <= 10; $v++) {
        seedDocumentVersion($siteId, 'live', $v, $old);
    }

    $this->artisan(PruneSiteDocumentVersionsCommand::class, ['--keep' => 3, '--min-age-days' => 7])
        ->assertSuccessful();

    expect(documentVersions($siteId))->toBe([8, 9, 10]);
});

it('deletes nothing when every version was built today, regardless of keep count', function () {
    [, $siteId] = buildTestSite();
    $today = now()->toDateTimeString();
    for ($v = 1; $v <= 10; $v++) {
        seedDocumentVersion($siteId, 'live', $v, $today);
    }

    $this->artisan(PruneSiteDocumentVersionsCommand::class, ['--keep' => 3, '--min-age-days' => 7])
        ->assertSuccessful();

    expect(documentVersions($siteId))->toBe(range(1, 10));
});

it('never deletes the newest version even when it is older than min-age-days', function () {
    [, $siteId] = buildTestSite();
    $veryOld = now()->subDays(60)->toDateTimeString();
    for ($v = 1; $v <= 5; $v++) {
        seedDocumentVersion($siteId, 'live', $v, $veryOld);
    }

    // keep=1 would otherwise try to prune everything below the newest —
    // the newest (5) must survive regardless.
    $this->artisan(PruneSiteDocumentVersionsCommand::class, ['--keep' => 1, '--min-age-days' => 7])
        ->assertSuccessful();

    expect(documentVersions($siteId))->toBe([5]);
});

it('bounds draft and live independently', function () {
    [, $siteId] = buildTestSite();
    $old = now()->subDays(30)->toDateTimeString();
    for ($v = 1; $v <= 5; $v++) {
        seedDocumentVersion($siteId, 'live', $v, $old);
        seedDocumentVersion($siteId, 'draft', $v, $old);
    }

    $this->artisan(PruneSiteDocumentVersionsCommand::class, ['--keep' => 2, '--min-age-days' => 7])
        ->assertSuccessful();

    expect(documentVersions($siteId, 'live'))->toBe([4, 5])
        ->and(documentVersions($siteId, 'draft'))->toBe([4, 5]);
});

it('leaves next-version arithmetic undisturbed — version = max + 1 still holds after a prune', function () {
    [$userId, $siteId] = buildTestSite();
    $old = now()->subDays(30)->toDateTimeString();
    for ($v = 1; $v <= 10; $v++) {
        seedDocumentVersion($siteId, 'live', $v, $old);
    }

    $this->artisan(PruneSiteDocumentVersionsCommand::class, ['--keep' => 3, '--min-age-days' => 7])
        ->assertSuccessful();

    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId);
    addItem($userId, 'video', 'A Video');
    BuildState::bump($siteId);

    $result = (new DocumentBuilder)->build($siteId);

    expect($result['version'])->toBe(11);
});

it('--dry-run mutates nothing', function () {
    [, $siteId] = buildTestSite();
    $old = now()->subDays(30)->toDateTimeString();
    for ($v = 1; $v <= 10; $v++) {
        seedDocumentVersion($siteId, 'live', $v, $old);
    }

    $this->artisan(PruneSiteDocumentVersionsCommand::class, ['--keep' => 3, '--min-age-days' => 7, '--dry-run' => true])
        ->assertSuccessful();

    expect(documentVersions($siteId))->toBe(range(1, 10));
});
