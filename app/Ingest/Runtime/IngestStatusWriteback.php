<?php

namespace App\Ingest\Runtime;

use Illuminate\Support\Facades\DB;

/**
 * Reports an ingest run's result back onto the connection it fetched for —
 * site.platform_connections.last_refresh_status and friends.
 *
 * Why this exists: the routing lane creates a connection 'pending' and hands
 * it to the ingest lane (SourceProvisioner → RunSourceJob). Nothing under
 * app/Ingest wrote the status back, and the only writers — ConnectFetchJob /
 * PlatformRefresher — are the LEGACY refresh lane, which this path never
 * dispatches. So a router-placed connection stayed 'pending' ("Syncing")
 * forever, even after its run finished (gsnwilliams / Fresha, 2026-08-18).
 *
 * Deliberately narrow: it only ever moves a row that is WAITING on this lane
 * ('pending' or 'action_needed'). A row the legacy lane already settled
 * ('ok' / 'error' / 'unavailable') is left alone — two lanes writing the same
 * column is exactly how a card says one thing and its panel another.
 *
 * 'action_needed' is the third meaning 'pending' used to carry by accident:
 * the run finished cleanly but the connector could not do its job until the
 * owner chooses something (a Fresha team member / storewide menu). It is a
 * resting state, not a fault — the dashboard shows "Action needed", the
 * legacy refresher skips it (scopeDueForRefresh), and the next successful
 * run clears it.
 */
final class IngestStatusWriteback
{
    /**
     * Note codes that mean "finished, but the owner has to act before this
     * connection can publish anything". Extend per connector; the connector
     * emits the Note, this list decides which Notes are blocking.
     */
    public const ACTION_NEEDED_NOTES = ['no_selection'];

    public function afterRun(string $sourceId, string $runId, string $outcome): void
    {
        $connectionId = DB::table('ingest.sources')->where('id', $sourceId)->value('connection_id');
        if (! is_string($connectionId) || $connectionId === '') {
            return;
        }

        $current = DB::table('site.platform_connections')
            ->where('id', $connectionId)
            ->whereNull('deleted_at')
            ->value('last_refresh_status');

        if (! in_array($current, ['pending', 'action_needed'], true)) {
            return;
        }

        [$status, $error] = $this->classify($runId, $outcome);
        if ($status === null) {
            return;
        }

        $update = [
            'last_refresh_status' => $status,
            'last_refresh_error' => $error,
            'updated_at' => now(),
        ];

        if ($status === 'ok') {
            $update['last_refreshed_at'] = now();
            $update['consecutive_failures'] = 0;
        }

        DB::table('site.platform_connections')->where('id', $connectionId)->update($update);
    }

    /**
     * @return array{0: ?string, 1: ?string} [status, error message] — null status = leave the row as it is
     */
    private function classify(string $runId, string $outcome): array
    {
        $blocking = $this->blockingNote($runId);
        if ($blocking !== null) {
            return ['action_needed', $blocking];
        }

        return match ($outcome) {
            'ok', 'not_modified', 'degraded' => ['ok', null],
            'unavailable' => ['unavailable', null],
            'error', 'shape' => ['error', null],
            // budget_skipped / deferred: the run did not happen yet — still owed.
            default => [null, null],
        };
    }

    private function blockingNote(string $runId): ?string
    {
        $detail = DB::table('ingest.runs')->where('id', $runId)->value('detail');
        if (! is_string($detail) || $detail === '') {
            return null;
        }

        $decoded = json_decode($detail, true);
        $notes = is_array($decoded) && is_array($decoded['notes'] ?? null) ? $decoded['notes'] : [];

        foreach ($notes as $note) {
            if (is_array($note) && in_array($note['code'] ?? null, self::ACTION_NEEDED_NOTES, true)) {
                return is_string($note['message'] ?? null) ? $note['message'] : (string) $note['code'];
            }
        }

        return null;
    }
}
