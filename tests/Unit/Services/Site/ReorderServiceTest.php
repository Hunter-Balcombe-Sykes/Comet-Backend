<?php

use App\Models\Core\Site\Block;
use App\Services\Site\ReorderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    $this->siteId = (string) Str::uuid();
});

/**
 * Insert a link-type Block row directly and return its id. Bypasses
 * Eloquent observers so tests stay isolated from observer side-effects.
 */
function insertTestBlock(string $siteId, int $sortOrder): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => $id,
        'site_id' => $siteId,
        'user_id' => (string) Str::uuid(),
        'block_group' => Block::GROUP_LINKS,
        'block_type' => Block::TYPE_LINK,
        'sort_order' => $sortOrder,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('places listed ids at sort_order 0..k and unlisted ids follow in original relative order', function () {
    $a = insertTestBlock($this->siteId, 0);
    $b = insertTestBlock($this->siteId, 1);
    $c = insertTestBlock($this->siteId, 2);

    $scope = Block::query()
        ->where('site_id', $this->siteId)
        ->where('block_group', Block::GROUP_LINKS)
        ->where('block_type', Block::TYPE_LINK);

    // Request order: [C, A]; B is unlisted and should follow
    app(ReorderService::class)->reorder([$c, $a], $scope, "blocks-links:{$this->siteId}");

    $ordered = Block::query()
        ->where('site_id', $this->siteId)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($ordered)->toBe([$c, $a, $b]);
});

it('aborts with 422 when a foreign id is included in the list', function () {
    $a = insertTestBlock($this->siteId, 0);
    $foreign = (string) Str::uuid();

    $scope = Block::query()
        ->where('site_id', $this->siteId)
        ->where('block_group', Block::GROUP_LINKS)
        ->where('block_type', Block::TYPE_LINK);

    expect(fn () => app(ReorderService::class)->reorder([$a, $foreign], $scope, "blocks-links:{$this->siteId}"))
        ->toThrow(HttpException::class);
});

it('invokes the afterCommit closure exactly once after the transaction', function () {
    $a = insertTestBlock($this->siteId, 0);
    $b = insertTestBlock($this->siteId, 1);

    $scope = Block::query()
        ->where('site_id', $this->siteId)
        ->where('block_group', Block::GROUP_LINKS)
        ->where('block_type', Block::TYPE_LINK);

    $callCount = 0;
    app(ReorderService::class)->reorder([$b, $a], $scope, "blocks-links:{$this->siteId}", function () use (&$callCount) {
        $callCount++;
    });

    expect($callCount)->toBe(1);
});

it('emits the pg_advisory_xact_lock SQL inside the transaction', function () {
    $a = insertTestBlock($this->siteId, 0);

    $scope = Block::query()
        ->where('site_id', $this->siteId)
        ->where('block_group', Block::GROUP_LINKS)
        ->where('block_type', Block::TYPE_LINK);

    $lockQueries = [];
    DB::listen(function ($query) use (&$lockQueries) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockQueries[] = $query->sql;
        }
    });

    app(ReorderService::class)->reorder([$a], $scope, "blocks-links:{$this->siteId}");

    expect($lockQueries)->toHaveCount(1);
});
