<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Routing\ConnectionIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Close the ledger row for an account the user connected some OTHER way.
 *
 * An intent is a question the router could not answer alone. Answering it —
 * Accept or Dismiss — settles it. But an account can also just ARRIVE, through
 * the connect sheet or an OAuth return, and nothing on those paths looks for a
 * standing intent naming it. So the question stayed open about something
 * already on the page.
 *
 * SuggestionsController::index() stopped RENDERING those cards (2026-08-26),
 * which fixed what the user sees. It could not also close them: SCALE-8 had
 * just taken every write off that handler, and a GET must not write. Hiding
 * without closing left the row 'proposed' forever, and
 * CheckStuckSourceIntentsCommand counts exactly that state pair — so the
 * LIFE-19 backlog alarm drifted upward with no path back down, because nobody
 * can answer a card they cannot see. This is that write, given a home where a
 * write belongs.
 *
 * 'superseded', not 'applied': the connection exists but this inbox did not
 * put it there. Keeping 'applied' to mean "the user answered the card" is what
 * lets the ledger still answer "did they actually say yes?". Not 'dismissed'
 * either — that is permanent by design, and would suppress a genuine re-offer
 * if the account is later disconnected.
 *
 * Scheduled at 06:10, ten minutes ahead of routing:stuck-intents, so the alarm
 * counts a ledger this has already tidied.
 */
class SettleConnectedSourceIntentsCommand extends Command
{
    protected $signature = 'routing:settle-connected {--dry-run : Report what would be settled and write nothing}';

    protected $description = 'Settle live source_intents whose account the user has already connected by another route.';

    /** Bounded so one run cannot hold a huge result set in memory. */
    private const CHUNK = 500;

    public function handle(ConnectionIdentity $identity): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $settled = 0;
        $scanned = 0;

        DB::table('routing.source_intents')
            ->whereIn('state', ['proposed', 'blocked'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($intents) use ($identity, $dryRun, &$settled, &$scanned): void {
                $scanned += $intents->count();

                // One connections query per (user, chunk) rather than per
                // intent — the same shape the inbox uses, for the same reason.
                foreach ($intents->groupBy('user_id') as $userId => $rows) {
                    $connections = IntegrationConnection::query()
                        ->where('user_id', $userId)
                        ->whereIn('surface_key', $rows->pluck('surface_key')->unique()->all())
                        ->whereNull('deleted_at')
                        ->get(['id', 'surface_key', 'resource_id', 'canonical_key', 'payload'])
                        ->groupBy('surface_key');

                    foreach ($rows as $intent) {
                        $match = $identity->matchWithin(
                            $connections->get($intent->surface_key, collect()),
                            (string) $intent->surface_key,
                            (string) $intent->identifier,
                        );

                        if ($match === null) {
                            continue;
                        }

                        $settled++;

                        if ($dryRun) {
                            $this->line("  would settle {$intent->surface_key} {$intent->identifier} -> connection {$match}");

                            continue;
                        }

                        // Re-asserts the live state in the WHERE: a user can
                        // answer the card between this chunk being read and
                        // this row being written, and their answer wins.
                        DB::table('routing.source_intents')
                            ->where('id', $intent->id)
                            ->whereIn('state', ['proposed', 'blocked'])
                            ->update([
                                'state' => 'superseded',
                                'connection_id' => $match,
                                'resolved_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }
            });

        $verb = $dryRun ? 'would settle' : 'settled';
        $this->info("Scanned {$scanned} live intents; {$verb} {$settled}.");

        if ($settled > 0 && ! $dryRun) {
            Log::info('routing.settle_connected', ['scanned' => $scanned, 'settled' => $settled]);
        }

        return self::SUCCESS;
    }
}
