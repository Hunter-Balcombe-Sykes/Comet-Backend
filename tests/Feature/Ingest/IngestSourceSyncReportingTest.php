<?php

// #LIFE-13 regression: IntegrationConnectionObserver::syncIngestSource() used
// to catch (QueryException) and log it with Log::debug — no report(), same
// silence whether the cause was a genuinely benign duplicate-save race or a
// real DB fault (a dropped connection, a missing table, a statement
// timeout). A user would see a platform connected that silently never
// syncs, with nothing surfaced anywhere loud enough to notice.
//
// The fix (paired with #LIFE-14's insertOrIgnore) splits the catch chain:
// UniqueConstraintViolationException — which SourceProvisioner's own insert
// no longer even raises, but a future racing write in this seam might —
// stays exactly as quiet as before; everything else now reports() and
// Log::warning()s. This test proves the split holds by exercising BOTH arms
// against the identical harness, so neither can pass by accident (a catch
// chain that always reports, or one that never does, would each make one of
// the two cases below fail).

use App\Ingest\SourceProvisioner;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

/** Builds a QueryException wrapping a PDOException with a forced SQLSTATE code (AdvisoryLockTest.php idiom). */
function ingestSyncQueryExceptionWithSqlstate(string $class, string $sqlstate, string $message): QueryException
{
    $pdoException = new PDOException($message);
    // PDOException::$code is untyped (holds a string SQLSTATE) but protected —
    // the real pdo_pgsql driver sets it internally, bypassing the public,
    // int-typed Exception::__construct() signature. Reflection is the only way
    // to reproduce that from userland PHP.
    $prop = new ReflectionProperty(PDOException::class, 'code');
    $prop->setAccessible(true);
    $prop->setValue($pdoException, $sqlstate);

    return new $class('pgsql', 'insert into "ingest"."sources" (...) values (...)', [], $pdoException);
}

dataset('ingest_sync_exceptions', [
    'quiet: a unique-constraint race' => [
        fn () => ingestSyncQueryExceptionWithSqlstate(
            UniqueConstraintViolationException::class,
            '23505',
            'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "sources_unique_per_connection"'
        ),
        false,
    ],
    'loud: a genuine DB fault' => [
        fn () => ingestSyncQueryExceptionWithSqlstate(
            QueryException::class,
            '40P01',
            'SQLSTATE[40P01]: Deadlock detected'
        ),
        true,
    ],
]);

it('reports and logs loudly ONLY when the fault is not a unique-constraint race', function (Closure $makeException, bool $shouldBeLoud) {
    $this->mock(SourceProvisioner::class)
        ->shouldReceive('sync')
        ->andThrow($makeException());

    Log::spy();
    $handler = $this->spy(ExceptionHandler::class);

    $userId = createTenant('ingest-sync-report-'.Str::lower(Str::random(6)))->id;
    IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => 'https://real.bandcamp.com'],
        'is_active' => true,
    ]);

    $message = 'IntegrationConnectionObserver ingest-source sync query failure';

    if ($shouldBeLoud) {
        $handler->shouldHaveReceived('report')
            ->withArgs(fn (Throwable $t) => $t instanceof QueryException);
        // Two args, message fixed, context any — Log::warning($message, $context)
        // is invoked with TWO arguments, so shouldHaveReceived('warning', [$message])
        // would demand a one-arg call that never happens and could never match.
        Log::shouldHaveReceived('warning', [$message, Mockery::any()]);
    } else {
        $handler->shouldNotHaveReceived('report');
        // Same arity note as above, mirrored for the negative assertion:
        // shouldNotHaveReceived('warning', [$message]) matches only a one-arg
        // call that never happens, so it would pass vacuously regardless of
        // what the code under test does (in-repo precedent:
        // tests/Feature/Routing/LinkInBioImporterTest.php:675).
        Log::shouldNotHaveReceived('warning', [$message, Mockery::any()]);
    }
})->with('ingest_sync_exceptions');
