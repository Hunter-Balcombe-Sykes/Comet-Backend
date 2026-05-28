<?php

namespace App\Services\Moderation;

use InvalidArgumentException;

/**
 * Computes the dedup_hash stored on moderation.case_signals.
 * Same reporter + same target + same reason = same hash.
 * UNIQUE index on case_signals.dedup_hash blocks duplicates at the DB level.
 */
class DedupHashCalculator
{
    public function forReport(
        string $reportableType,
        string $reportableId,
        string $reasonCode,
        ?string $reporterEmail,
        ?string $reporterIpHash,
    ): string {
        if ($reporterEmail === null && $reporterIpHash === null) {
            throw new InvalidArgumentException(
                'DedupHashCalculator requires either reporter_email or reporter_ip_hash.'
            );
        }

        $identity = $reporterEmail !== null
            ? 'email:' . strtolower(trim($reporterEmail))
            : 'ip:' . $reporterIpHash;

        return hash('sha256', implode('|', [
            'content_report',
            $reportableType,
            $reportableId,
            $reasonCode,
            $identity,
        ]));
    }

    public function forCsamMatch(string $cloudflareMatchId, string $siteMediaId): string
    {
        return hash('sha256', implode('|', [
            'csam_scan',
            $siteMediaId,
            $cloudflareMatchId,
        ]));
    }
}
