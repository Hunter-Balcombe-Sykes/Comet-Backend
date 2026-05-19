<?php

namespace App\Services\Exports;

use Illuminate\Support\Facades\Storage;

/**
 * Streams an iterable of normalised rows to a JSONL temp file, hashes + sizes
 * it, and uploads to R2. One file per chunk job, written under
 *   exports/commissions/{prof}/{audit}/parts/chunk-{n}.jsonl
 *
 * JSONL is chosen as the intermediate format because:
 *  - append-friendly (each chunk is self-contained, no header sharing)
 *  - line-delimited so the finalizer can stream it back in O(1) memory
 *  - format-agnostic: the finalizer decides CSV vs XLSX, not us
 */
class JsonlPartWriter
{
    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{row_count: int, size: int, sha256: string}
     */
    public function writePart(string $disk, string $remotePath, iterable $rows): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cep_part_');
        $handle = fopen($tmp, 'w');
        $count = 0;

        try {
            foreach ($rows as $row) {
                fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
                $count++;
            }
            fclose($handle);

            $size = filesize($tmp) ?: 0;
            $sha256 = hash_file('sha256', $tmp);

            // Streaming upload to avoid loading the part into PHP memory.
            $stream = fopen($tmp, 'rb');
            Storage::disk($disk)->put($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return ['row_count' => $count, 'size' => $size, 'sha256' => $sha256];
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
