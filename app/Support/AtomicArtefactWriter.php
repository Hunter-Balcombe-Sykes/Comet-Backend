<?php

namespace App\Support;

use App\Exceptions\Support\ArtefactWriteException;

/**
 * Writes a generated artefact (compiled catalog, routing corpus) durably:
 * mkdir, write-to-tmp, rename — with every step's return value checked
 * (OBS-1, OBS-8). `file_put_contents()` returns a short `int`, not `false`,
 * on a partial write — the disk-full case the audit named — so this checks
 * the byte count, not just falsiness.
 *
 * These commands are developer/CI tools, not scheduled tasks (routing:corpus
 * and catalog:compile are absent from routes/console.php) — the observer for
 * a write failure here is the terminal already watching stderr plus a
 * non-zero exit, not Nightwatch. Callers throw, catch, and translate to
 * self::FAILURE; they do not report().
 */
final class AtomicArtefactWriter
{
    /** @throws ArtefactWriteException */
    public static function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            // @-suppressed: a concurrent creator winning the race is not a
            // failure — only the directory still missing afterward is.
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new ArtefactWriteException($path, "could not create directory {$dir}");
            }
        }

        $tmp = $path.'.tmp';
        $written = @file_put_contents($tmp, $contents);
        if ($written === false || $written !== strlen($contents)) {
            @unlink($tmp);

            throw new ArtefactWriteException($path, 'write to temp file failed or was truncated');
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new ArtefactWriteException($path, "rename {$tmp} -> {$path} failed");
        }
    }
}
