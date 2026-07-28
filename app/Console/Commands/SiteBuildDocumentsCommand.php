<?php

namespace App\Console\Commands;

use App\Jobs\Site\BuildSiteDocumentJob;
use App\Site\Documents\DocumentBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// The DocumentBuilder's caller (plan §9). Three modes:
//   site:build-documents {user}          — build that user's site NOW, inline,
//                                          and print the result (dev/support).
//   site:build-documents --stale         — queue a build per site whose
//                                          content_revision moved past its
//                                          built_revision (the 5-minute
//                                          sweeper: the net under observer
//                                          bumps and raw-write seams).
//   site:build-documents --all           — queue every published site (fleet
//                                          rebuild after a BUILDER_REVISION
//                                          bump).
class SiteBuildDocumentsCommand extends Command
{
    protected $signature = 'site:build-documents
        {user? : Build this user id\'s site inline and report}
        {--stale : Queue builds for every site with unbuilt content revisions}
        {--all : Queue builds for every published site}
        {--channel=live : Document channel (live|draft)}';

    protected $description = 'Build versioned site documents from sections + content items.';

    public function handle(DocumentBuilder $builder): int
    {
        $channel = (string) $this->option('channel');

        if (($user = $this->argument('user')) !== null) {
            $siteId = DB::table('site.sites')->where('user_id', $user)->whereNull('deleted_at')->value('id');
            if ($siteId === null) {
                $this->error("No site for user {$user}.");

                return self::FAILURE;
            }

            $result = $builder->build((string) $siteId, $channel);
            $this->info(sprintf('site %s: %s (version %s)', $siteId, $result['status'], $result['version'] ?? '—'));

            return self::SUCCESS;
        }

        if ($this->option('stale')) {
            $siteIds = DB::table('site.site_build_state')
                ->whereColumn('content_revision', '>', 'built_revision')
                ->pluck('site_id');
        } elseif ($this->option('all')) {
            $siteIds = DB::table('site.sites')
                ->where('is_published', true)
                ->whereNull('deleted_at')
                ->pluck('id');
        } else {
            $this->error('Pass a user id, --stale, or --all.');

            return self::INVALID;
        }

        foreach ($siteIds as $siteId) {
            BuildSiteDocumentJob::dispatch((string) $siteId, $channel);
        }

        $this->info(sprintf('Queued %d document build(s).', $siteIds->count()));

        return self::SUCCESS;
    }
}
