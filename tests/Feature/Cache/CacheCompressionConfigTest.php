<?php

// Pins the value-encoding shape of the redis.cache connection.
//
// Two invariants, both learned by measurement on 2026-07-28 (dev, phpredis 6.3.0):
//
//   1. Compression, never a serializer. RedisStore::serialize() already returns
//      serialize($value), so phpredis is handed a string and there is no object
//      graph left for a serializer to encode. SERIALIZER_IGBINARY measured
//      8,248 B against 8,256 B uncompressed — nothing — while LZ4 alone took the
//      same payload to 2,616 B. The config previously wired both behind one flag
//      and a comment crediting igbinary with the saving, which is why this is a
//      test and not a comment.
//
//   2. The encoding is confined to the cache connection. All five connections in
//      config/database.php share one Valkey instance and differ only by database
//      index — 0 (queue + Horizon), 1 (cache), 2 (sessions), 3 (dormant), 4
//      (cache locks). Compression on DB 0 would encode Horizon job payloads that
//      Horizon's own tooling reads without these options; on DB 4 it would encode
//      lock values read through a separate store binding. Asymmetric encoding
//      across connections is the real hazard here, not the codec choice.
//
// The first two tests read the config SOURCE rather than the resolved value on
// purpose: with the flag off, array_filter() strips the key entirely, so a
// runtime check would pass even if a serializer were re-added behind an
// off-by-default flag. The invariant is a property of the file, not of this
// environment's .env.
//
// Assertions are written as toBeTrue/toBeFalse rather than toContain/toHaveKey
// because only the boolean expectations accept a failure message — toContain()
// is variadic over needles, and toHaveKey() takes the message third, after an
// expected value. Passing a message to either silently changes what is asserted.
//
// Design: docs/superpowers/specs/2026-07-28-redis-cache-compression-design.md
// Research: docs/superpowers/research/cache-gold-standard-2026-07-28.md §2.5

use Illuminate\Support\Facades\File;

// function_exists guard: unnamespaced Pest files share one global symbol table,
// so an unguarded helper here would fatal the whole suite on a name collision.
if (! function_exists('partnaCacheConnectionSource')) {
    function partnaCacheConnectionSource(): string
    {
        $source = File::get(config_path('database.php'));

        // Narrow to the 'cache' connection block so an encoding option on some
        // other connection isn't silently absorbed by a whole-file match.
        $start = strpos($source, "'cache' => [");
        expect($start !== false)->toBeTrue(
            'Could not locate the redis.cache connection block in config/database.php.'
        );

        $end = strpos($source, "'session' => [", (int) $start);
        expect($end !== false)->toBeTrue(
            'Could not locate the end of the redis.cache connection block (expected the session '
            .'connection to follow it in config/database.php).'
        );

        $block = substr($source, (int) $start, (int) $end - (int) $start);

        // Strip // comments. The block's own comment explains why there is no
        // serializer and necessarily names SERIALIZER_IGBINARY to do it — an
        // unstripped scan reads that explanation as the violation. Same lesson
        // as CacheKeyspaceConstraintsTest matching `->forever(` rather than the
        // bare word: a guard that fires on prose gets suppressed, and then it
        // protects nothing.
        return (string) preg_replace('#^\s*//.*$#m', '', $block);
    }
}

it('does not wire a serializer onto the cache connection', function () {
    $block = partnaCacheConnectionSource();

    // Both forms: the array key that would activate it, and the constant that
    // would supply its value.
    $declaresSerializer = str_contains($block, "'serializer'") || str_contains($block, 'SERIALIZER_');

    expect($declaresSerializer)->toBeFalse(
        'The redis.cache connection declares a phpredis serializer. Measured 2026-07-28 on dev: '
        .'SERIALIZER_IGBINARY stored a real pro:model payload in 8,248 B against 8,256 B with no '
        .'serializer at all, and 2,624 B against LZ4-alone 2,616 B when the two were paired. '
        .'Laravel serializes to a string before phpredis sees the value, so a serializer has '
        .'nothing to encode and only costs CPU on the authenticated hot path. Use compression only.'
    );
});

it('reads compression from REDIS_CACHE_COMPRESSION', function () {
    $block = partnaCacheConnectionSource();

    expect(str_contains($block, 'REDIS_CACHE_COMPRESSION'))->toBeTrue(
        'The redis.cache compression flag has been renamed. An environment that still sets the old '
        .'name would silently lose compression on its next deploy, and every value written while it '
        .'was off then sits uncompressed alongside compressed ones.'
    );

    expect(str_contains($block, 'REDIS_IGBINARY'))->toBeFalse(
        'REDIS_IGBINARY is retired. It gated a serializer that measured as a no-op; the flag that '
        .'does the work is REDIS_CACHE_COMPRESSION.'
    );
});

it('confines value encoding to the cache connection', function () {
    // DB 0 carries Horizon job state, DB 4 carries locks read via a separate
    // store binding. Neither tolerates an encoding the reader doesn't share.
    $mustBeUnencoded = ['default', 'session', 'queue', 'cache_locks'];

    foreach ($mustBeUnencoded as $connection) {
        $options = config("database.redis.{$connection}.options", []) ?? [];

        expect(array_key_exists('compression', $options))->toBeFalse(
            "The redis.{$connection} connection declares compression. Only redis.cache may encode "
            .'values: every other connection is read by tooling that does not share these options.'
        );

        expect(array_key_exists('serializer', $options))->toBeFalse(
            "The redis.{$connection} connection declares a serializer. Only redis.cache may encode "
            .'values, and even it uses compression only — see the sibling test in this file.'
        );
    }
});
