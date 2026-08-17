<?php

namespace App\Console\Commands;

use App\Models\Core\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DEV ONLY. Drive the connect matrix: for every platform key in a fixtures
 * JSON, POST each fixture (url form + handle form) through the real kernel
 * as a user, poll deferred 202s, and record what LANDED — connection row,
 * ingest.sources row, status. Output is a JSON report the overnight LOG
 * quotes from. Written for the 2026-08-18 run.
 *
 * Fixture shape (per key): {input:{field}|[{field,platform}], fixtures:[{url,handle,label}]}
 * apple is two keys (apple/music artist, apple/podcast show); google-business
 * and instagram are skipped here (paid / place-id driven) and probed by hand.
 */
class ConnectSweepCommand extends Command
{
    protected $signature = 'partna:connect-sweep
        {handle : user to act as}
        {--fixtures= : path to fixtures.json}
        {--only= : comma list of keys}
        {--skip=google-business,instagram : comma list of keys to skip}
        {--out= : write JSON report here}
        {--poll=45 : seconds to poll deferred connects}';

    protected $description = 'DEV ONLY: connect every fixture for every platform key and record what landed.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing in production.');

            return self::FAILURE;
        }
        $user = User::query()->where('handle', $this->argument('handle'))->firstOrFail();
        AsUserRequestCommand::actAs($user);

        $fixtures = json_decode((string) file_get_contents((string) $this->option('fixtures')), true);
        $only = array_filter(explode(',', (string) $this->option('only')));
        $skip = array_filter(explode(',', (string) $this->option('skip')));
        $report = [];

        foreach ($fixtures as $key => $spec) {
            if ($key === 'extras' || in_array($key, $skip, true) || ($only && ! in_array($key, $only, true))) {
                continue;
            }
            $cases = $this->cases($key, $spec);
            foreach ($cases as $case) {
                $t0 = microtime(true);
                $res = AsUserRequestCommand::send('POST', "/api/platforms/{$case['route']}/connect", json_encode($case['body']));
                $status = $res->getStatusCode();
                $json = json_decode((string) $res->getContent(), true) ?? [];
                $entry = [
                    'key' => $key, 'route' => $case['route'], 'form' => $case['form'], 'body' => $case['body'],
                    'http' => $status, 'ms' => (int) ((microtime(true) - $t0) * 1000),
                    'message' => $json['message'] ?? null,
                    'errors' => $json['errors'] ?? null,
                    'account_id' => $json['id'] ?? ($json['connection']['id'] ?? null),
                    'name' => $json['name'] ?? ($json['connection']['name'] ?? null),
                ];
                if ($status === 202 && isset($json['statusUrl'])) {
                    $entry['deferred'] = $this->pollDeferred($json['statusUrl']);
                }
                $entry['landed'] = $this->landed($user->getKey(), $key, $case['route']);
                $report[] = $entry;
                $this->line(sprintf('%-18s %-6s %s %4dms %s', $key, $case['form'], $status, $entry['ms'], $entry['message'] ?? ($entry['name'] ?? '')));
            }
        }

        if ($out = $this->option('out')) {
            file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Wrote {$out}");
        }

        return self::SUCCESS;
    }

    /** @return list<array{route:string,form:string,body:array}> */
    private function cases(string $key, array $spec): array
    {
        $out = [];
        $inputs = $spec['input'] ?? [];
        // apple: two sub-platforms with their own field
        if ($key === 'apple') {
            foreach ($spec['fixtures'] as $f) {
                $isPodcast = str_contains((string) ($f['url'] ?? ''), 'podcast');
                $field = $isPodcast ? 'show' : 'artist';
                $route = $isPodcast ? 'apple/podcast' : 'apple/music';
                $value = $f['handle'] ?? $f['label'] ?? $f['url'];
                $out[] = ['route' => $route, 'form' => $field, 'body' => [$field => $value]];
                if (! empty($f['url'])) {
                    $out[] = ['route' => $route, 'form' => $field.'-url', 'body' => [$field => $f['url']]];
                }
            }

            return $out;
        }
        $field = is_array($inputs) && isset($inputs['field']) ? $inputs['field'] : 'url';
        foreach ($spec['fixtures'] as $f) {
            if (! empty($f['url'])) {
                $out[] = ['route' => $key, 'form' => 'url', 'body' => [$field => $f['url']]];
            }
            if (! empty($f['handle'])) {
                $out[] = ['route' => $key, 'form' => 'handle', 'body' => [$field => $f['handle']]];
            }
        }

        return $out;
    }

    private function pollDeferred(string $statusUrl): array
    {
        $path = parse_url($statusUrl, PHP_URL_PATH).'?'.(parse_url($statusUrl, PHP_URL_QUERY) ?? '');
        $deadline = microtime(true) + (int) $this->option('poll');
        $last = null;
        while (microtime(true) < $deadline) {
            $res = AsUserRequestCommand::send('GET', $path);
            $last = json_decode((string) $res->getContent(), true) ?? ['http' => $res->getStatusCode()];
            if (($last['status'] ?? null) !== 'pending') {
                break;
            }
            usleep(1500000);
        }

        return ['final' => $last['status'] ?? null, 'name' => $last['connection']['name'] ?? null, 'raw' => $last];
    }

    private function landed(string $userId, string $key, string $route): array
    {
        $rows = DB::table('site.platform_connections')
            ->where('user_id', $userId)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('surface_key', 'like', str_replace(['-', '_'], ['%', '%'], explode('/', $route)[0]).'%')
                ->orWhere('platform', explode('/', $route)[0]))
            ->orderByDesc('created_at')->limit(3)
            ->get(['id', 'surface_key', 'resource_id', 'last_refresh_status', 'is_active', 'payload', 'display_settings']);
        $conn = $rows->first();
        $src = $conn ? DB::table('ingest.sources')->where('connection_id', $conn->id)->first(['source_key', 'identifier', 'auto_sync', 'next_attempt_at', 'last_run_at', 'health']) : null;
        $payload = $conn ? (json_decode((string) $conn->payload, true) ?: []) : [];

        return [
            'connections' => $rows->count(),
            'surface_key' => $conn->surface_key ?? null,
            'last_refresh_status' => $conn->last_refresh_status ?? null,
            'is_active' => $conn->is_active ?? null,
            'payload_keys' => array_keys($payload),
            'payload_name' => $payload['name'] ?? ($payload['username'] ?? ($payload['handle'] ?? null)),
            'ingest' => $src ? (array) $src : null,
        ];
    }
}
