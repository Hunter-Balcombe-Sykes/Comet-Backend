<?php

use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Jobs\Platforms\WebsiteMenuPdfScanJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\DesignKitAutopilot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// #JOB-1 load-bearing proof. A property assertion (ScanPreviousWebsiteContentJob
// having $tries === 1) is NOT proof the retry stopped happening — it only
// proves the value is set. This drives a REAL database-queue worker through a
// full drain and counts actual dispatches/attempts, exactly like production
// Horizon would, so the assertion means "the fan-out was not re-run" rather
// than "a property reads correctly".
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
    setupDesignKitsTable();
    setupNotificationsTable();
    setupSiteMediaTable();
    setupQueueTables();
    config(['queue.default' => 'database']);
    // Tests/TestCase.php forces database.default to 'pgsql' (a second,
    // separately-connected in-memory SQLite handle — see its own comment) so
    // every Eloquent model and every Pest DB helper in this suite lands on
    // 'pgsql'. queue.php's 'database' connection and 'failed.database' both
    // default from env('DB_CONNECTION') (raw env, 'sqlite' per phpunit.xml),
    // which is a DIFFERENT :memory: handle than 'pgsql' — without pinning
    // both to 'pgsql' here, the queue worker would look for `jobs`/
    // `failed_jobs` on a connection where setupQueueTables() never created
    // them ("no such table"), and failed jobs would silently fail to log.
    config(['queue.connections.database.connection' => 'pgsql']);
    config(['queue.failed.database' => 'pgsql']);
});

/**
 * jobs + failed_jobs tables for the 'database' queue driver, on the same
 * SQLite testing connection everything else in this suite uses (no separate
 * Pest helper existed for this before — added here, per the plan, as the
 * first consumer; a second consumer should promote this into tests/Pest.php).
 */
function setupQueueTables(): void
{
    if (! Schema::hasTable('jobs')) {
        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    if (! Schema::hasTable('failed_jobs')) {
        Schema::create('failed_jobs', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
}

/**
 * Repeatedly runs `queue:work --once` against the 'database' connection's
 * 'scraping' queue (where ScanPreviousWebsiteContentJob and its throwaway
 * probe below both live) until nothing is immediately available, bounded by
 * $maxIterations so a bug that keeps releasing a job can't hang the suite.
 * Delayed sub-jobs (30s+ delays) are deliberately left un-popped — this test
 * only needs to see whether they were EVER inserted into `jobs`, not run them.
 */
function drainDatabaseQueue(int $maxIterations = 10): void
{
    $queue = config('partna.queues.scraping', 'scraping');

    for ($i = 0; $i < $maxIterations; $i++) {
        $ready = DB::table('jobs')
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->exists();

        if (! $ready) {
            break;
        }

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queue,
            '--once' => true,
        ]);
    }
}

/**
 * Local copy of the fixture idiom ScanPreviousWebsiteContentJobTest.php's
 * spwcjUser() uses — NOT reused directly because Pest test files each define
 * their own global-namespace functions and cross-file availability depends on
 * load order (repo gotcha: unnamespaced Pest files share a global symbol
 * table, but only once loaded).
 */
function retryTestUser(string $handle, string $accountType = 'business', string $sector = 'restaurant'): array
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => $accountType, 'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$handle}@mail.example.com",
    ]);
    $site = Site::factory()->for($user, 'user')->create();

    return [$user, $site];
}

it('does not re-dispatch the billed OCR sub-job on retry — real queue-drain proof', function () {
    [$user, $site] = retryTestUser('spwcjretry1', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="/menu.pdf">Menu (PDF)</a>', 200)]);

    // Correct injection point per the plan: DesignKitAutopilot::persistFillIfEmpty()
    // is the LAST statement in handle() (:342-343), strictly after every sub-job
    // dispatch (WebsiteMenuPdfScanJob included). Forcing a throw here means
    // attempt 1 completes the WHOLE paid fan-out and only THEN fails — exactly
    // the "retry after side effects already happened" state under test. It's
    // resolved via app(DesignKitAutopilot::class) (not constructor-injected),
    // so a duck-typed container swap is enough — no need to fight its real
    // constructor dependencies.
    app()->bind(DesignKitAutopilot::class, fn () => new class
    {
        public function fromWebsiteEvidence(string $html): array
        {
            throw new RuntimeException('forced failure after fan-out — proves a retry does not re-run the fan-out');
        }
    });

    $scanAttempts = 0;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$scanAttempts) {
        if (str_contains($event->job->resolveName(), ScanPreviousWebsiteContentJob::class)) {
            $scanAttempts++;
        }
    });

    ScanPreviousWebsiteContentJob::dispatch((string) $user->id, (string) $site->id, 'https://example.com');

    drainDatabaseQueue();

    // The scan job itself: exactly one real execution attempt, and it landed
    // in failed_jobs exactly once (no queue-level retry re-ran handle()).
    expect($scanAttempts)->toBe(1);
    expect(DB::table('failed_jobs')->where('payload', 'like', '%'.addcslashes(ScanPreviousWebsiteContentJob::class, '\\').'%')->count())->toBe(1);
    expect(DB::table('jobs')->where('payload', 'like', '%'.addcslashes(ScanPreviousWebsiteContentJob::class, '\\').'%')->count())->toBe(0);

    // The billed sub-job: dispatched exactly once across the whole drain —
    // BEFORE this fix, a second handle() attempt would have pushed a second
    // WebsiteMenuPdfScanJob payload for the same PDF, re-billing Mistral OCR.
    // These rows are delayed 30s+ and deliberately never popped by the drain
    // above (see drainDatabaseQueue's docblock) — only their COUNT matters.
    expect(DB::table('jobs')->where('payload', 'like', '%'.addcslashes(WebsiteMenuPdfScanJob::class, '\\').'%')->count())->toBe(1);
});

// Harness-sensitivity arm. Without this, the `=== 1` assertions above could
// mean "the harness cannot see a retry happen at all" rather than "the retry
// did not happen" — this proves the drain helper DOES observe a real second
// attempt when one is supposed to occur, so the test above is actually
// load-bearing.
it('harness sensitivity: the drain helper observes a real retry when tries allows one', function () {
    RetryProbeJob::$executions = 0;

    RetryProbeJob::dispatch();

    drainDatabaseQueue();

    expect(RetryProbeJob::$executions)->toBe(2);
    expect(DB::table('failed_jobs')->where('payload', 'like', '%RetryProbeJob%')->count())->toBe(1);
});

/**
 * Inline throwaway job, $tries = 2, no backoff (immediately re-available on
 * release) — increments a static counter and always throws. Its only purpose
 * is proving drainDatabaseQueue() can observe a genuine second execution
 * attempt of the SAME job instance, the same shape as the real regression
 * this file guards against.
 */
class RetryProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static int $executions = 0;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function handle(): void
    {
        self::$executions++;

        throw new RuntimeException('probe: always fails, to force a retry up to $tries');
    }
}
