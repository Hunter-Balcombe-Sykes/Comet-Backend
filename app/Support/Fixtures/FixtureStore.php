<?php

namespace App\Support\Fixtures;

use InvalidArgumentException;

/** Writes one recorded body under <root>/<source>/<name>.<ext> and registers it. */
final class FixtureStore
{
    public const SOURCES = ['instagram', 'places', 'fresha', 'linkinbio', 'websites', 'shop', 'media', 'menus'];

    public function __construct(
        private readonly string $root,
        private readonly FixtureManifest $manifest,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  source_url, captured_by, notes — sha256/captured_at are filled here
     * @return string the manifest-relative path written
     */
    public function put(string $source, string $name, string $ext, string $body, array $meta): string
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown fixture source '{$source}'. Known: ".implode(', ', self::SOURCES));
        }
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name)) {
            throw new InvalidArgumentException("Fixture name '{$name}' must be lowercase [a-z0-9._-]");
        }

        $redacted = FixtureRedactor::apply($source, $body, $ext);
        $rel = "{$source}/{$name}.{$ext}";
        $abs = rtrim($this->root, '/').'/'.$rel;

        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        file_put_contents($abs, $redacted);

        $this->manifest->upsert($rel, [
            'source_url' => (string) ($meta['source_url'] ?? ''),
            'captured_at' => now()->toIso8601String(),
            'sha256' => hash('sha256', $redacted),
            'captured_by' => (string) ($meta['captured_by'] ?? 'manual'),
            'notes' => (string) ($meta['notes'] ?? ''),
        ]);

        return $rel;
    }
}
