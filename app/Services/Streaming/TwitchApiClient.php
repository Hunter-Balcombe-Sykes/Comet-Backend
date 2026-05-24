<?php

namespace App\Services\Streaming;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the Twitch Helix streams endpoint to check live status.
 * Accepts up to 100 handles per request (Twitch API limit).
 */
class TwitchApiClient
{
    private const STREAMS_URL = 'https://api.twitch.tv/helix/streams';

    public function __construct(
        private StreamingTokenManager $tokens
    ) {}

    /**
     * Returns the subset of $handles that are currently live on Twitch.
     * Max 100 handles per call — caller (LiveStatusPoller) must batch.
     *
     * @param  string[]  $handles
     * @return string[]
     */
    public function getLiveHandles(array $handles): array
    {
        if (empty($handles)) {
            return [];
        }

        $token = $this->tokens->getToken('twitch');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'twitch']);

            return [];
        }

        // Twitch requires repeated query params: ?user_login=a&user_login=b
        // http_build_query flattens arrays, so build manually.
        $query = implode('&', array_map(
            fn ($h) => 'user_login='.urlencode($h),
            $handles
        ));

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Client-ID' => (string) config('services.twitch.client_id'),
            ])->get(self::STREAMS_URL.'?'.$query);

            if (! $response->successful()) {
                // Privacy: Twitch error responses may echo back session data
                // (auth tokens, channel metadata). Platform + status is enough
                // diagnostic signal; don't persist body content into Nightwatch.
                Log::error('streaming.api_error', [
                    'platform' => 'twitch',
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json('data', []);

            return array_values(array_column(
                array_filter($data, fn ($s) => ($s['type'] ?? '') === 'live'),
                'user_login'
            ));
        } catch (\Throwable $e) {
            Log::error('streaming.api_error', [
                'platform' => 'twitch',
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
