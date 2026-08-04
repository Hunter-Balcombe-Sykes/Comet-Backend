<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

// V2: Sets PostgreSQL statement and lock timeouts on the default connection at boot.
class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Skip the eager PDO setup when running tests. The default connection
        // is still 'pgsql' in test contexts (so models extending BaseModel work),
        // but `php artisan config:clear` runs before phpunit.xml's DB_CONNECTION=sqlite
        // override, which would otherwise force a real Supabase TCP connect during
        // the composer-test preflight and fail in any sandbox without DNS.
        //
        // DB_APPLY_TIMEOUTS_IN_TESTS=1 is the single opt-in escape hatch: set only
        // by the postgres-tests CI job (a real, reachable Postgres, never Supabase),
        // so tests/Postgres/DatabaseTimeoutsTest.php can prove these SET statements
        // still fire. Every other test lane — local sandboxes included — keeps the
        // unconditional bail above unchanged.
        if ($this->app->runningUnitTests() && getenv('DB_APPLY_TIMEOUTS_IN_TESTS') !== '1') {
            return;
        }

        // Set PostgreSQL timeouts ONCE per connection, not per query
        $connectionName = config('database.default');

        if ($connectionName !== 'pgsql') {
            return;
        }

        try {
            $pdo = DB::connection()->getPdo();

            $statementTimeout = config('database.connections.pgsql.statement_timeout', 30000);
            $lockTimeout = config('database.connections.pgsql.lock_timeout', 10000);

            $pdo->exec("SET statement_timeout = {$statementTimeout}");
            $pdo->exec("SET lock_timeout = {$lockTimeout}");
        } catch (\PDOException) {
            // Connection unavailable (e.g. missing credentials during config:clear)
        }
    }
}
