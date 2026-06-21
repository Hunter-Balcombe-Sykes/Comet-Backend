<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\LogoProcessorException;
use Illuminate\Support\Facades\Http;

/**
 * Client for the self-hosted logo processor (Cloudflare Worker + Container).
 *
 * Sends the original image bytes; receives a background-removed PNG plus an
 * optional vectorized SVG. Stateless on both ends — this client ships bytes and
 * gets bytes back; the container holds no storage credentials, so Comet-Backend
 * remains the sole writer to R2.
 *
 * Auth: a shared bearer token (config partna.logo_removal.token). The fronting
 * Worker rejects any request without it.
 */
class LogoProcessorClient
{
    /**
     * @param  string  $imageBytes  Raw original image bytes.
     * @param  string  $filename  Original filename (for the multipart part; cosmetic).
     * @param  string  $mime  Original MIME type.
     *
     * @throws LogoProcessorException on misconfiguration, transport error, or a malformed response.
     */
    public function process(string $imageBytes, string $filename, string $mime): LogoProcessorResult
    {
        $url = rtrim((string) config('partna.logo_removal.url', ''), '/');
        $token = (string) config('partna.logo_removal.token', '');
        $timeout = (int) config('partna.logo_removal.timeout', 120);

        if ($url === '' || $token === '') {
            throw new LogoProcessorException('Logo processor URL or token is not configured.');
        }

        try {
            $response = Http::withToken($token)
                ->timeout($timeout)
                ->attach('image', $imageBytes, $filename !== '' ? $filename : 'logo', ['Content-Type' => $mime])
                ->post("{$url}/process");
        } catch (\Throwable $e) {
            throw new LogoProcessorException('Logo processor request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new LogoProcessorException(
                "Logo processor returned HTTP {$response->status()}: ".mb_substr((string) $response->body(), 0, 300)
            );
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['png_transparent']) || ! is_string($data['png_transparent'])) {
            throw new LogoProcessorException('Logo processor response missing png_transparent.');
        }

        $png = base64_decode($data['png_transparent'], true);
        if ($png === false || $png === '') {
            throw new LogoProcessorException('Logo processor returned an undecodable png_transparent.');
        }

        $svg = $data['svg'] ?? null;
        if (! is_string($svg)) {
            $svg = null;
        }

        return new LogoProcessorResult(
            pngTransparent: $png,
            svg: $svg,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }
}
