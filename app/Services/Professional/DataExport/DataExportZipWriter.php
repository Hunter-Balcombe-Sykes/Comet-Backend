<?php

namespace App\Services\Professional\DataExport;

use Generator;
use RuntimeException;
use ZipArchive;

// V2: Streams a payload into a temp .zip on disk. Returns the path, SHA-256
// hash, byte size, and a record_counts summary for the audit row.
//
// Single entry point — writeStreaming(builder, $profId) — drives the builder
// row-by-row so peak memory stays bounded regardless of tenant size. GDPR
// exports must not OOM. A legacy fully-materialised write() existed in earlier
// revisions and was removed once production paths (ExportProfessionalDataJob)
// were migrated to the streaming variant — having two emission paths invited
// schema drift between them (the legacy path silently lost new sections after
// Bundle B7).
class DataExportZipWriter
{
    /**
     * Stream the builder's sections into a zip on disk. Drives one DB cursor
     * per unbounded section and writes rows straight to data.json (and any
     * applicable CSV) without ever holding the whole result set in memory.
     *
     * @return array{path: string, sha256: string, size: int, record_counts: array<string, int>}
     */
    public function writeStreaming(DataExportPayloadBuilder $builder, string $professionalId): array
    {
        $zipPath = $this->reserveZipPath();
        $jsonPath = tempnam(sys_get_temp_dir(), 'export-json-');
        if ($jsonPath === false) {
            throw new RuntimeException('Failed to create temp file for export json.');
        }

        $jh = fopen($jsonPath, 'wb');
        if ($jh === false) {
            throw new RuntimeException("Failed to open temp json for writing: {$jsonPath}");
        }

        /** @var array<string, array{path: string, fp: resource, columns: array<string>}> $csvHandles */
        $csvHandles = [];

        $recordCounts = [];

        // Group emission state. The builder emits dotted-name sections like
        // 'audit.handle_change_log' that should be assembled into JSON objects.
        // We open a group on its first child and close it when we either
        // transition to a different group or to a top-level section. This
        // preserves the builder's declared order in the output JSON, which
        // matters for any consumer that hashes data.json.
        $currentGroup = null;
        $firstChildInGroup = true;
        $first = true;

        try {
            fwrite($jh, "{\n");

            foreach ($builder->stream($professionalId) as $section) {
                $name = $section['name'];
                $isNested = str_contains($name, '.');
                if ($isNested) {
                    [$groupName, $childKey] = explode('.', $name, 2);
                } else {
                    $groupName = null;
                    $childKey = null;
                }

                // Close the previous group if we're switching to a different
                // group, OR transitioning back to a top-level section.
                if ($currentGroup !== null && $groupName !== $currentGroup) {
                    fwrite($jh, '}');
                    $currentGroup = null;
                }

                if ($isNested) {
                    // Open the group lazily on first child.
                    if ($currentGroup !== $groupName) {
                        if (! $first) {
                            fwrite($jh, ",\n");
                        }
                        fwrite($jh, json_encode($groupName).': {');
                        $currentGroup = $groupName;
                        $firstChildInGroup = true;
                        $first = false;
                    }

                    if (! $firstChildInGroup) {
                        fwrite($jh, ',');
                    }

                    if ($section['kind'] === 'value') {
                        fwrite($jh, json_encode($childKey).':'.json_encode($section['value'], JSON_UNESCAPED_SLASHES));
                    } else {
                        fwrite($jh, json_encode($childKey).':');
                        $count = $this->streamRowsArray($jh, $section['rows'], $csvHandles, $section['csv_columns'] ?? null, $name);
                        $recordCounts[$this->recordCountKey($name)] = $count;
                    }
                    $firstChildInGroup = false;
                } else {
                    if ($section['kind'] === 'value') {
                        $this->writeJsonEntry($jh, $name, $section['value'], $first);
                    } else {
                        $this->beginJsonEntry($jh, $name, $first);
                        $count = $this->streamRowsArray($jh, $section['rows'], $csvHandles, $section['csv_columns'] ?? null, $name);
                        $recordCounts[$this->recordCountKey($name)] = $count;
                    }
                    $first = false;
                }
            }

            // Close any group still open at the end.
            if ($currentGroup !== null) {
                fwrite($jh, '}');
            }

            fwrite($jh, "\n}");
        } finally {
            if (is_resource($jh)) {
                fclose($jh);
            }
            foreach ($csvHandles as $h) {
                if (is_resource($h['fp'])) {
                    fclose($h['fp']);
                }
            }
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            @unlink($jsonPath);
            throw new RuntimeException("Failed to open zip for writing: {$zipPath}");
        }

        $zip->addFile($jsonPath, 'data.json');
        foreach ($csvHandles as $name => $h) {
            $zip->addFile($h['path'], $name);
        }
        $zip->close();

        // ZipArchive holds source files open until close(); only delete the
        // backing temp files after the archive is finalised.
        $sha = hash_file('sha256', $zipPath);
        $size = filesize($zipPath);

        @unlink($jsonPath);
        foreach ($csvHandles as $h) {
            @unlink($h['path']);
        }

        return [
            'path' => $zipPath,
            'sha256' => $sha,
            'size' => $size,
            'record_counts' => $this->normaliseRecordCounts($recordCounts),
        ];
    }

    private function reserveZipPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');
        if ($path === false) {
            throw new RuntimeException('Failed to create temp file for export zip.');
        }
        // tempnam creates an empty file; ZipArchive::CREATE refuses to overwrite a
        // non-zip file in some PHP versions. Unlink it so ZipArchive opens fresh.
        @unlink($path);

        return $path.'.zip';
    }

    /**
     * Stream a sequence of row arrays as a JSON array literal. Also routes
     * each row to a CSV file if csv_columns is set. Returns the row count.
     *
     * @param  resource  $jh
     * @param  array<string, array{path: string, fp: resource, columns: array<string>}>  $csvHandles
     */
    private function streamRowsArray($jh, Generator $rows, array &$csvHandles, ?array $csvColumns, string $sectionName): int
    {
        fwrite($jh, '[');
        $count = 0;
        foreach ($rows as $row) {
            if ($count > 0) {
                fwrite($jh, ',');
            }
            fwrite($jh, json_encode($row, JSON_UNESCAPED_SLASHES));
            if ($csvColumns !== null) {
                $this->writeCsvRow($csvHandles, $sectionName, $csvColumns, $row);
            }
            $count++;
        }
        fwrite($jh, ']');

        return $count;
    }

    /**
     * @param  resource  $jh
     */
    private function writeJsonEntry($jh, string $key, mixed $value, bool $first): void
    {
        if (! $first) {
            fwrite($jh, ",\n");
        }
        fwrite($jh, json_encode($key).': '.json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  resource  $jh
     */
    private function beginJsonEntry($jh, string $key, bool $first): void
    {
        if (! $first) {
            fwrite($jh, ",\n");
        }
        fwrite($jh, json_encode($key).': ');
    }

    /**
     * @param  array<string, array{path: string, fp: resource, columns: array<string>}>  $csvHandles
     */
    private function writeCsvRow(array &$csvHandles, string $sectionName, array $columns, array $row): void
    {
        $csvName = $this->csvNameFor($sectionName);
        if (! isset($csvHandles[$csvName])) {
            $path = tempnam(sys_get_temp_dir(), 'export-csv-');
            if ($path === false) {
                throw new RuntimeException('Failed to create temp file for csv.');
            }
            $fp = fopen($path, 'wb');
            if ($fp === false) {
                throw new RuntimeException("Failed to open csv temp for writing: {$path}");
            }
            fputcsv($fp, $columns);
            $csvHandles[$csvName] = ['path' => $path, 'fp' => $fp, 'columns' => $columns];
        }

        $line = [];
        foreach ($columns as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($csvHandles[$csvName]['fp'], $line);
    }

    private function csvNameFor(string $sectionName): string
    {
        return match ($sectionName) {
            'customers' => 'customers.csv',
            'enquiries' => 'enquiries.csv',
            default => str_replace('.', '_', $sectionName).'.csv',
        };
    }

    /**
     * Normalise record-count keys to the legacy flat schema audit consumers
     * expect: audit.handle_change_log → handle_change_log, etc.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function normaliseRecordCounts(array $counts): array
    {
        $out = [];
        foreach ($counts as $key => $value) {
            $out[$this->recordCountKey($key)] = $value;
        }

        return $out;
    }

    private function recordCountKey(string $sectionName): string
    {
        $tail = strrchr($sectionName, '.');

        return $tail === false ? $sectionName : ltrim($tail, '.');
    }
}
