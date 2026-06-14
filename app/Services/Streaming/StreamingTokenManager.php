<?php

namespace App\Services\Streaming;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Manages OAuth Client Credentials tokens for Twitch and Kick.
 * Tokens are stored in Redis with TTL = (expires_in - 300) seconds.
 * A SET NX lock prevents concurrent refreshes.
 */
class StreamingTokenManager
{
    private const TOKEN_KEY_PREFIX = 'streaming:token:';

    private const REFRESH_LOCK_PREFIX = 'streaming:token:refresh:';

    private const EXPIRY_BUFFER_SECONDS = 300;

    /** @var array<string, array{token_url: string, client_id_key: string, client_secret_key: string}> */
    private const PLATFORM_CONFIG = [
        'twitch' => [
            'token_url' => 'https://id.twitch.tv/oauth2/token',
            'client_id_key' => 'services.twitch.client_id',
            'client_secret_key' => 'services.twitch.client_secret',
        ],
        'kick' => [
            'token_url' => 'https://id.kick.com/oauth/token',
            'client_id_key' => 'services.kick.client_id',
            'client_secret_key' => 'services.kick.client_secret',
        ],
    ];

    /** Returns a valid bearer token for $platform, refreshing if needed. */
    public function getToken(string $platform): ?string
    {
        $cfg = self::PLATFORM_CONFIG[$platform] ?? null;
        if (! $cfg) {
            return null;
        }

        // Check the cache before validating credentials — a cached token is
        // valid regardless of current config state.
        $cached = Redis::get(self::TOKEN_KEY_PREFIX.$platform);
        if ($cached) {
            return $cached;
        }

        $clientId = config($cfg['client_id_key']);
        $clientSecret = config($cfg['client_secret_key']);
        if (! $clientId || ! $clientSecret) {
            return null;
        }

        return $this->refreshToken($platform, $cfg, (string) $clientId, (string) $clientSecret);
    }

    /** @param array<string, string> $cfg */
    private function refreshToken(string $platform, array $cfg, string $clientId, string $clientSecret): ?string
    {
        // Atomic lock — only one process refreshes at a time.
        $lockKey = self::REFRESH_LOCK_PREFIX.$platform;
        $locked = Redis::set($lockKey, '1', 'EX', 30, 'NX');

        if (! $locked) {
            // Another process is refreshing — wait briefly and read what they wrote.
            usleep(500_000);

            return Redis::get(self::TOKEN_KEY_PREFIX.$platform) ?: null;
        }

        try {
            $response = Http::asForm()->post($cfg['token_url'], [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            if (! $response->successful()) {
                Log::error('streaming.auth_failure', [
                    'platform' => $platform,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $token = $response->json('access_token');
            $expiresIn = (int) ($response->json('expires_in') ?? 3600);
            $ttl = max(60, $expiresIn - self::EXPIRY_BUFFER_SECONDS);

            Redis::set(self::TOKEN_KEY_PREFIX.$platform, $token, 'EX', $ttl);

            return $token;
        } catch (\Throwable $e) {
            report($e);
            Log::error('streaming.auth_failure', [
                'platform' => $platform,
                'message' => $e->getMessage(),
            ]);

            return null;
        } finally {
            Redis::del($lockKey);
        }
    }
}
