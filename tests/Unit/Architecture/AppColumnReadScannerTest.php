<?php

use Tests\Support\Architecture\AppColumnReadScanner;

it('resolves an aliased table and its qualified select columns', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            $records = DB::table('ingest.record_state as rs')
                ->join('ingest.record_versions as rv', 'rv.id', '=', 'rs.current_version_id')
                ->where('rs.stream_id', $streamId)
                ->select(['rs.key', 'rs.last_seen_run', 'rv.doc'])
                ->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect($refs['ingest.record_state'])->toContain('key')
        ->and($refs['ingest.record_state'])->toContain('last_seen_run')
        ->and($refs['ingest.record_state'])->toContain('stream_id')
        ->and($refs['ingest.record_versions'])->toContain('doc');
});

it('attributes unqualified columns only on a single-table chain', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function heal() {
            DB::table('content.media_assets')
                ->whereIn('id', $batch)
                ->whereNull('mirror_eligible')
                ->update(['mirror_eligible' => true]);
        }
    }
    PHP;

    expect(AppColumnReadScanner::scanSource($php)['content.media_assets'])
        ->toContain('mirror_eligible')
        ->toContain('id');
});

it('does not attribute unqualified columns when the chain joins a second table', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            DB::table('content.items')
                ->join('content.source_items as si', 'si.item_id', '=', 'content.items.id')
                ->where('coord', $c)
                ->get();
        }
    }
    PHP;

    // 'coord' is ambiguous across the two tables — must be dropped, not guessed.
    expect(AppColumnReadScanner::scanSource($php)['content.items'] ?? [])->not->toContain('coord');
});

it('terminates a chain that begins inside an enclosing paren', function () {
    // Regression: depth never returns to 0, so a naive scan ran 17499 chars on.
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            if (DB::table('content.items')->where('user_id', $u)->exists()) {
                $x = 1;
            }
            DB::table('content.source_items')->where('coord', $c)->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect($refs['content.items'])->toContain('user_id')
        ->and($refs['content.items'])->not->toContain('coord')
        ->and($refs['content.source_items'])->toContain('coord');
});

it('ignores dotted string literals that are not column references', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            Log::info('analytics.ingest.dropped');
            $host = 'api.deezer.com';
            DB::table('content.items')->where('id', $id)->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect(array_keys($refs))->toBe(['content.items'])
        ->and($refs['content.items'])->toContain('id');
});

it('skips an alias bound to two different tables rather than guessing', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            DB::table('content.f_place as fp')
                ->join('content.f_published as fp', 'fp.item_id', '=', 'x.id')
                ->select(['fp.name'])
                ->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect($refs['content.f_place'] ?? [])->not->toContain('name')
        ->and($refs['content.f_published'] ?? [])->not->toContain('name');
});

it('resolves a fully qualified schema.table.column reference', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            DB::table('content.sources')
                ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
                ->whereNull('site.platform_connections.deleted_at')
                ->get();
        }
    }
    PHP;

    expect(AppColumnReadScanner::scanSource($php)['site.platform_connections'])
        ->toContain('deleted_at')->toContain('id');
});

it('is not confused by an apostrophe inside a PHP comment', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            // Set only by the mapper's per-platform offers pass.
            DB::table('content.items')->where('removed_at', null)->get();
        }
    }
    PHP;

    expect(AppColumnReadScanner::scanSource($php)['content.items'])->toContain('removed_at');
});
