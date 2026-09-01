<?php

use Tests\Support\Architecture\PostgresLaneDdlScanner;
use Tests\TestCase;

// base_path() in the third case needs the app booted — Unit tests don't get
// that by default (see tests/Pest.php), so opt in file-locally.
uses(TestCase::class)->in(__FILE__);

it('counts columns added by an ADD COLUMN IF NOT EXISTS heal', function () {
    $dir = sys_get_temp_dir().'/pglane_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/ExampleTest.php', <<<'PHP'
    <?php
    $pg->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id uuid PRIMARY KEY,
        user_id uuid NULL
    )');
    $pg->statement('ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true');
    PHP);

    $byFile = PostgresLaneDdlScanner::laneDdlByFile($dir);

    expect($byFile['ExampleTest.php']['site.platform_connections'])
        ->toContain('id')->toContain('user_id')->toContain('is_active');
});

it('counts columns added by the foreach heal-array idiom', function () {
    $dir = sys_get_temp_dir().'/pglane_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/HealTest.php', <<<'PHP'
    <?php
    $pg->statement('CREATE TABLE IF NOT EXISTS content.sources (
        id uuid PRIMARY KEY
    )');
    foreach ([
        'content.sources' => ['connection_id' => 'uuid', 'kind' => "text NOT NULL DEFAULT 'manual'"],
    ] as $table => $columns) {
        foreach ($columns as $col => $type) {
            $pg->statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$col} {$type}");
        }
    }
    PHP);

    $byFile = PostgresLaneDdlScanner::laneDdlByFile($dir);

    expect($byFile['HealTest.php']['content.sources'])
        ->toContain('connection_id')->toContain('kind');
});

it('associates a flat foreach heal-array with the literal table named in its ALTER statement', function () {
    $dir = sys_get_temp_dir().'/pglane_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/FlatHealTest.php', <<<'PHP'
    <?php
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid()
    )');
    foreach ([
        'handle' => 'character varying(63)',
        'handle_lc' => 'character varying(63)',
        'deleted_at' => 'timestamptz',
        'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        'updated_at' => 'timestamptz NOT NULL DEFAULT now()',
    ] as $col => $type) {
        $pg->statement("ALTER TABLE core.users ADD COLUMN IF NOT EXISTS {$col} {$type}");
    }
    PHP);

    $byFile = PostgresLaneDdlScanner::laneDdlByFile($dir);

    expect($byFile['FlatHealTest.php']['core.users'])
        ->toContain('id')
        ->toContain('handle')
        ->toContain('handle_lc')
        ->toContain('deleted_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

it('never emits a bare "if" pseudo-column from IF NOT EXISTS on an interpolated ADD COLUMN', function () {
    $dir = sys_get_temp_dir().'/pglane_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/IfLeakTest.php', <<<'PHP'
    <?php
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid()
    )');
    foreach ([
        'handle' => 'character varying(63)',
    ] as $col => $type) {
        $pg->statement("ALTER TABLE core.users ADD COLUMN IF NOT EXISTS {$col} {$type}");
    }
    PHP);

    $byFile = PostgresLaneDdlScanner::laneDdlByFile($dir);

    expect($byFile['IfLeakTest.php']['core.users'])->not->toContain('if');
});

it('leaves the existing drift() contract untouched', function () {
    $drift = PostgresLaneDdlScanner::drift(base_path('supabase/migrations'), base_path('tests/Postgres'));

    expect($drift)->toHaveKeys(['tables', 'columns']);
});
