<?php

namespace App\Services\Streaming;

use App\Exceptions\Streaming\KickRateLimitException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the Kick public channels endpoint to check live status.
 * Accepts up to KICK_BATCH_SIZE handles per request — caller (LiveStatusPoller) batches.
 *
 * Endpoint: https://api.kick.com/public/v1/channels?slug=a&slug=b
 * Verify current shape at https://dev.kick.com before editing.
 *
 * Throws KickRateLimitException on 429 so the poller can flip the circuit breaker.
 */
class KickApiClient
{
    private const CHANNELS_URL = 'https://api.kick.com/public/v1/channels';

    public const KICK_BATCH_SIZE = 50;

    public function __construct(
        private StreamingTokenManager $tokens
    ) {}

    /**
     * Returns the subset of $handles that are currently live on Kick.
     * Caller must batch into groups <= KICK_BATCH_SIZE.
     *
     * @param  string[]  $handles
     * @return string[]
     *
     * @throws KickRateLimitException when Kick returns 429
     */
    public function getLiveHandles(array $handles): array
    {
        if (empty($handles)) {
            return [];
        }

        $token = $this->tokens->getToken('kick');
        if (! $token) {
            Log::error('streaming.auth_failure', ['platform' => 'kick']);

            return [];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->get(self::CHANNELS_URL, ['slug' => $handles]);

            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?? 60);
                throw new KickRateLimitException($retryAfter);
            }

            if (! $response->successful()) {
                // Privacy: Kick error responses may include free-text bodies or
                // echo back session data. Platform + status is enough for a
                // poller-side circuit-breaker decision; don't persist body content
                // into Nightwatch retention.
                Log::error('streaming.api_error', [
                    'platform' => 'kick',
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json('data', []);

            return array_values(array_filter(
                array_map(fn ($entry) => $entry['slug'] ?? null, $data),
                function ($slug) use ($data) {
                    if (! is_string($slug)) {
                        return false;
                    }
                    // Find the matching entry and check live flag. The exact path may be
                    // `stream.is_live`, `livestream` (non-null), or `is_live` depending on
                    // Kick API version — adjust here if response shape has drifted.
                    foreach ($data as $entry) {
                        if (($entry['slug'] ?? null) === $slug) {
                            return (bool) ($entry['stream']['is_live']
                                ?? (! is_null($entry['livestream'] ?? null))
                                ?: false);
                        }
                    }

                    return false;
                }
            ));
        } catch (KickRateLimitException $e) {
            throw $e; // poller handles
        } catch (\Throwable $e) {
            report($e);
            Log::error('streaming.api_error', [
                'platform' => 'kick',
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
