<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Sets ETag on cacheable public GET responses and returns 304 when the client's
// If-None-Match header matches — saves bandwidth on revalidation.
class AddETagHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isCacheable($request, $response)) {
            return $response;
        }

        $etag = $this->computeETag($response);
        $response->headers->set('ETag', '"'.$etag.'"');

        if ($this->clientHasCurrentVersion($request, $etag)) {
            $response->setStatusCode(304);
            $response->setContent('');
        }

        return $response;
    }

    private function isCacheable(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        // Authenticated responses are private, no-store — never ETag them.
        if ($request->headers->has('Authorization')) {
            return false;
        }

        if (! $response->isSuccessful()) {
            return false;
        }

        $path = $request->path();
        foreach (AddPublicCacheHeaders::CACHEABLE_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function computeETag(Response $response): string
    {
        $contentType = (string) $response->headers->get('Content-Type', '');
        $body = (string) $response->getContent();

        // Normalise JSON key order so the same payload always hashes identically,
        // regardless of insertion order differences across code paths.
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $body = (string) json_encode($this->sortKeysRecursive($decoded));
            }
        }

        return md5($body);
    }

    private function sortKeysRecursive(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        // Preserve list (integer-keyed) order; sort only object (string-keyed) arrays.
        if (array_is_list($data)) {
            return array_map(fn ($item) => $this->sortKeysRecursive($item), $data);
        }

        ksort($data);

        return array_map(fn ($item) => $this->sortKeysRecursive($item), $data);
    }

    /**
     * Check whether the client's If-None-Match value matches our ETag.
     * Handles quoted ETags ("abc") and weak validators (W/"abc") in comma-delimited lists.
     */
    private function clientHasCurrentVersion(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if ($header === null || $header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $normalized = trim(ltrim(trim($candidate), 'W/'), '"');
            if ($normalized === $etag) {
                return true;
            }
        }

        return false;
    }
}
