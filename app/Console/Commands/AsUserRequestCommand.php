<?php

namespace App\Console\Commands;

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Models\Core\User\User;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEV ONLY. Send one authenticated API request AS a user through the real
 * HTTP kernel — every middleware, policy, controller, observer and job
 * dispatch runs exactly as it would for the dashboard — without a Supabase
 * JWT. Uses the same VerifySupabaseJwt container stub the test suite's
 * actingAsUser() uses (tests/Pest.php).
 *
 *   php artisan partna:as broken-oven POST /api/platforms/youtube/connect --json='{"url":"https://youtube.com/@x"}'
 *   php artisan partna:as broken-oven GET  /api/platforms/meta
 *
 * Exists so the 2026-08-18 overnight run can drive the connect matrix and
 * authenticated verbs from scripts with a real user context. Refuses in
 * production.
 */
class AsUserRequestCommand extends Command
{
    protected $signature = 'partna:as
        {handle : user handle to act as}
        {method : GET|POST|PATCH|PUT|DELETE}
        {uri : e.g. /api/platforms/meta}
        {--json= : JSON body}
        {--query= : URL query string a=b&c=d}
        {--raw : print body only, no status line}';

    protected $description = 'DEV ONLY: run one API request as a user through the HTTP kernel (no JWT needed).';

    public function handle(): int
    {
        if (app()->isProduction() || (string) config('app.env') === 'production') {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $user = User::query()->where('handle', $this->argument('handle'))->first();
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        self::actAs($user);

        $method = strtoupper((string) $this->argument('method'));
        $uri = (string) $this->argument('uri');
        if ($q = $this->option('query')) {
            $uri .= (str_contains($uri, '?') ? '&' : '?').$q;
        }
        $body = (string) ($this->option('json') ?? '');

        $response = self::send($method, $uri, $body !== '' ? $body : null);

        if (! $this->option('raw')) {
            $this->line("HTTP {$response->getStatusCode()}");
        }
        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);
        $this->line($decoded === null ? $content : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $response->getStatusCode() < 400 ? self::SUCCESS : self::FAILURE;
    }

    /** Bind the JWT stub so every kernel request in this process runs as $user. */
    public static function actAs(User $user): void
    {
        $uid = (string) ($user->auth_user_id ?? Str::uuid());
        $claims = [
            'sub' => $uid,
            'email' => $user->primary_email,
            'email_verified' => true,
            'aal' => 'aal1',
            'amr' => [],
            'session_id' => (string) Str::uuid(),
        ];

        app()->bind(VerifySupabaseJwt::class, fn () => new class($uid, $claims)
        {
            public function __construct(private readonly string $uid, private readonly array $claims) {}

            public function handle(Request $request, Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', $this->claims);
                $request->attributes->set('supabase_aal', 'aal1');
                $request->attributes->set('supabase_amr', []);
                $request->attributes->set('supabase_session_id', $this->claims['session_id']);
                $request->attributes->set('supabase_revocation_verified', true);

                return $next($request);
            }
        });
    }

    /** One request through the real kernel (after actAs()). */
    public static function send(string $method, string $uri, ?string $jsonBody = null): Response
    {
        $request = Request::create($uri, strtoupper($method), [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_USER_AGENT' => 'partna-as/overnight',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $jsonBody);

        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
