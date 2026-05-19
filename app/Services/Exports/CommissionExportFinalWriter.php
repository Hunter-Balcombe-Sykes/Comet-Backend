<?php

namespace App\Services\Exports;

use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Reads JSONL part files from R2 line-by-line and writes the consolidated
 * CSV or XLSX to a local temp path. Memory is bounded — never holds more than
 * one parsed row at a time. The caller (FinalizerJob) re-uploads the local
 * temp file to R2 + signs it.
 *
 * Header set matches the legacy sync export so spreadsheet consumers don't break:
 *   date, type, description, counterparty, amount_cents, currency, status, payout_id, stripe_id
 */
class CommissionExportFinalWriter
{
    private const HEADER = [
        'date', 'type', 'description', 'counterparty',
        'amount_cents', 'currency', 'status', 'payout_id', 'stripe_id',
    ];

    /**
     * @param  list<string>  $partPaths  R2 keys, in chunk-index order
     * @return array{size: int, sha256: string, row_count: int}
     */
    public function writeFinal(string $format, string $outputPath, array $partPaths, string $disk): array
    {
        return match ($format) {
            'csv' => $this->writeCsv($outputPath, $partPaths, $disk),
            'xlsx' => $this->writeXlsx($outputPath, $partPaths, $disk),
            default => throw new RuntimeException("unsupported format: {$format}"),
        };
    }

    private function writeCsv(string $outputPath, array $partPaths, string $disk): array
    {
        $out = fopen($outputPath, 'w');
        fputcsv($out, self::HEADER);
        $count = 0;

        try {
            foreach ($this->streamRows($partPaths, $disk) as $row) {
                fputcsv($out, $this->shapeRow($row));
                $count++;
            }
        } finally {
            fclose($out);
        }

        return [
            'size' => filesize($outputPath) ?: 0,
            'sha256' => hash_file('sha256', $outputPath),
            'row_count' => $count,
        ];
    }

    private function writeXlsx(string $outputPath, array $partPaths, string $disk): array
    {
        $writer = new XlsxWriter;
        $writer->openToFile($outputPath);
        $writer->addRow(Row::fromValues(self::HEADER));
        $count = 0;

        try {
            foreach ($this->streamRows($partPaths, $disk) as $row) {
                $writer->addRow(Row::fromValues($this->shapeRow($row)));
                $count++;
            }
        } finally {
            $writer->close();
        }

        return [
            'size' => filesize($outputPath) ?: 0,
            'sha256' => hash_file('sha256', $outputPath),
            'row_count' => $count,
        ];
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamRows(array $partPaths, string $disk): \Generator
    {
        $fs = Storage::disk($disk);

        foreach ($partPaths as $path) {
            $stream = $fs->readStream($path);
            if (! is_resource($stream)) {
                continue;
            }
            try {
                while (($line = fgets($stream)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /**
     * Maps the JSONL row shape (from StripeRowGenerator) to the 9-column flat shape.
     *
     * Counterparty inference: for charge/refund (brand-side rows), the affiliate is
     * the counterparty; for transfer/reversal (affiliate-side rows), the brand is.
     *
     * stripe_id: prefer the composite `id` field (e.g. "charge:ch_xxx") which is always
     * present in the JSONL; fall back to raw_stripe_id for compatibility with older
     * row shapes that set raw_stripe_id but not id.
     *
     * @param  array<string, mixed>  $row
     * @return list<scalar>
     */
    private function shapeRow(array $row): array
    {
        $type = $row['type'] ?? '';
        $counterparty = in_array($type, ['transfer', 'reversal'], true)
            ? ($row['brand']['name'] ?? '')
            : ($row['affiliate']['name'] ?? '');

        return [
            $row['occurred_at'] ?? '',
            $type,
            $row['description'] ?? '',
            $counterparty,
            $row['amount_cents'] ?? 0,
            $row['currency_code'] ?? 'AUD',
            $row['status'] ?? '',
            $row['payout_id'] ?? '',
            $row['id'] ?? $row['raw_stripe_id'] ?? '',
        ];
    }
}
