<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

// Nudges the owner about enquiries still unread 48h after arrival. One
// reminder per enquiry EVER — the publisher's dedupe key is the enquiry id, so
// re-runs and overlapping schedules are no-ops. The window is bounded at 7
// days so enabling this on an old backlog doesn't flood anyone's inbox.
class NotifyUnansweredEnquiries extends Command
{
    protected $signature = 'partna:notify-unanswered-enquiries {--dry-run}';

    protected $description = 'Remind owners about enquiries unread for 48 hours';

    public function handle(NotificationPublisher $publisher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        Enquiry::query()
            ->whereNull('read_at')
            ->whereNotIn('status', ['spam', 'archived', 'replied'])
            ->whereBetween('created_at', [now()->subDays(7), now()->subHours(48)])
            ->orderBy('created_at')
            ->chunkById(200, function ($enquiries) use ($publisher, $dryRun, &$count): void {
                foreach ($enquiries as $enquiry) {
                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $name = trim((string) $enquiry->name) !== '' ? trim((string) $enquiry->name) : 'A visitor';
                    $subject = Str::limit(trim((string) $enquiry->subject), 60);

                    $publisher->publish(
                        userId: (string) $enquiry->user_id,
                        frontendType: 'Warning',
                        category: 'enquiry_reminder',
                        title: "{$name} is still waiting to hear back",
                        body: "Their enquiry \"{$subject}\" arrived on {$enquiry->created_at->toFormattedDateString()} and hasn't been opened yet. A quick reply keeps the lead warm.",
                        dedupeKey: "enquiry_reminder:{$enquiry->id}",
                        ctaUrl: '/account/features/enquiries',
                        primaryActionLabel: 'Open enquiries',
                        retentionConfigKey: 'enquiry_reminder',
                        critical: false,
                    );
                }
            });

        $this->info(($dryRun ? '[dry-run] would remind about ' : 'Published reminders for ')."{$count} enquiries.");

        return self::SUCCESS;
    }
}
