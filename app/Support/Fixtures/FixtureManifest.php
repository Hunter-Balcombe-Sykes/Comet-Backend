<?php

namespace App\Support\Fixtures;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MANIFEST.json for tests/fixtures/recorded/: one row per recorded file so a
 * capture is traceable (where from, when, what hash) and an unregistered file
 * — or a hand-edited one — turns the guard test red.
 */
final class FixtureManifest
{
    public const VERSION = 1;

    /** @var array{version:int, entries: array<string, array<string, mixed>>}|null */
    private ?array $data = null;

    public function __construct(private readonly string $manifestPath) {}

    /** @return array{version:int, entries: array<string, array<string, mixed>>} */
    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if (! is_file($this->manifestPath)) {
            return $this->data = ['version' => self::VERSION, 'entries' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->manifestPath), true);

        return $this->data = [
            'version' => (int) ($decoded['version'] ?? self::VERSION),
            'entries' => is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function entries(): array
    {
        return $this->load()['entries'];
    }

    /** @param array<string, mixed> $entry */
    public function upsert(string $rel, array $entry): void
    {
        $data = $this->load();
        $data['entries'][$rel] = $entry + ($data['entries'][$rel] ?? []);
        ksort($data['entries']);
        $this->data = $data;
        $this->flush();
    }

    public function remove(string $rel): void
    {
        $data = $this->load();
        unset($data['entries'][$rel]);
        $this->data = $data;
        $this->flush();
    }

    /**
     * Compare manifest to disk. Every problem is one actionable line.
     *
     * @return list<string>
     */
    public function verify(string $root): array
    {
        $root = rtrim($root, '/');
        $problems = [];
        $entries = $this->entries();

        foreach ($entries as $rel => $entry) {
            $abs = $root.'/'.$rel;
            if (! is_file($abs)) {
                $problems[] = "missing file: {$rel}";

                continue;
            }
            if (($entry['sha256'] ?? null) !== hash_file('sha256', $abs)) {
                $problems[] = "hash mismatch: {$rel}";
            }
        }

        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getFilename() === 'MANIFEST.json' || $file->getFilename() === '.gitkeep') {
                    continue;
                }
                $rel = ltrim(str_replace($root, '', str_replace('\\', '/', $file->getPathname())), '/');
                if (! isset($entries[$rel])) {
                    $problems[] = "orphan file: {$rel}";
                }
            }
        }

        sort($problems);

        return $problems;
    }

    private function flush(): void
    {
        file_put_contents(
            $this->manifestPath,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
    }
}
