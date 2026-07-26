<?php

namespace App\Services\V5\Scraping\BaseTemplates;

use App\Services\V5\Scraping\BaseScraper;
use Illuminate\Support\Facades\Http;

// V5 ApiBase — for platforms that use a JSON REST API.
// Handles: API key / OAuth auth, pagination (cursor, offset, page),
// rate limiting (Retry-After), error categorization (transient vs permanent).
//
// Usage: extend this, set $endpoint + $auth, add field mappings.
abstract class ApiBase extends BaseScraper
{
    protected string $endpoint = '';
    protected string $authType = 'none'; // 'none', 'api_key', 'bearer', 'oauth2'
    protected string $apiKey = '';
    protected string $apiKeyHeader = 'Authorization';
    protected string $apiKeyPrefix = 'Bearer ';
    protected int $timeout = 15;
    protected int $retries = 2;
    protected int $retryDelayMs = 500;

    /** Make an authenticated GET request. */
    protected function apiGet(string $path = '', array $query = []): ?array
    {
        $url = $this->endpoint.$path;
        $headers = $this->buildAuthHeaders();

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            if ($attempt > 0) {
                usleep($this->retryDelayMs * 1000 * $attempt);
            }

            try {
                $response = Http::withHeaders($headers)
                    ->timeout($this->timeout)
                    ->get($url, $query);

                if ($response->successful()) {
                    return $response->json();
                }

                // Retry-After header
                if ($response->status() === 429) {
                    $retryAfter = (int) $response->header('Retry-After', 5);
                    sleep(min($retryAfter, 30));
                    continue;
                }

                // Permanent errors — don't retry
                if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429) {
                    $this->logFailure(static::class, 'api_get', "HTTP {$response->status()}: {$url}");
                    return null;
                }

                // 5xx — retry
                continue;
            } catch (\Throwable $e) {
                if ($attempt === $this->retries) {
                    $this->logFailure(static::class, 'api_get', $e->getMessage());
                    return null;
                }
            }
        }

        return null;
    }

    /** Make an authenticated POST request. */
    protected function apiPost(string $path = '', array $body = []): ?array
    {
        $url = $this->endpoint.$path;
        $headers = $this->buildAuthHeaders();

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->post($url, $body);

            if ($response->successful()) {
                return $response->json();
            }

            $this->logFailure(static::class, 'api_post', "HTTP {$response->status()}");
            return null;
        } catch (\Throwable $e) {
            $this->logFailure(static::class, 'api_post', $e->getMessage());
            return null;
        }
    }

    /** Iterate over paginated results using the configured strategy. */
    protected function paginate(string $path, array $query = [], string $strategy = 'offset', int $maxPages = 10): array
    {
        $all = [];
        $page = 0;

        do {
            $pageQuery = match ($strategy) {
                'offset' => array_merge($query, ['offset' => $page * 50, 'limit' => 50]),
                'cursor' => array_merge($query, $page > 0 ? ['cursor' => $cursor ?? ''] : []),
                'page' => array_merge($query, ['page' => $page + 1, 'per_page' => 50]),
                default => $query,
            };

            $data = $this->apiGet($path, $pageQuery);
            if (! $data) break;

            $items = $this->extractItems($data);
            if (empty($items)) break;

            $all = array_merge($all, $items);

            $cursor = $this->extractCursor($data);
            $page++;
        } while (count($items) >= 50 && $page < $maxPages && ($strategy !== 'cursor' || $cursor));

        return $all;
    }

    /** Override to extract item array from API response envelope. */
    protected function extractItems(array $response): array
    {
        return $response['data'] ?? $response['items'] ?? $response['results'] ?? $response;
    }

    /** Override to extract pagination cursor from API response. */
    protected function extractCursor(array $response): ?string
    {
        return $response['cursor'] ?? $response['next_cursor'] ?? $response['pagination']['next'] ?? null;
    }

    private function buildAuthHeaders(): array
    {
        return match ($this->authType) {
            'api_key' => [$this->apiKeyHeader => $this->apiKey],
            'bearer' => [$this->apiKeyHeader => $this->apiKeyPrefix.$this->apiKey],
            'oauth2' => ['Authorization' => 'Bearer '.$this->resolveOAuthToken()],
            default => [],
        };
    }

    /** Override for OAuth token refresh logic. */
    protected function resolveOAuthToken(): string
    {
        return $this->apiKey;
    }
}
