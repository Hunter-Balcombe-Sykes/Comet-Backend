<?php

// tests/Support/Catalog/SweepProbeUrl.php

namespace Tests\Support\Catalog;

/** Turns a compiled surface into ONE probe URL for the classification sweep. */
final class SweepProbeUrl
{
    /** Placeholder → sample value. Anything else falls back to 'acme'. */
    private const SAMPLES = [
        'handle' => 'acme', 'slug' => 'acme', 'store' => 'acme', 'username' => 'acme', 'user' => 'acme',
        'id' => '1234567', 'event_id' => '1234567', 'video_id' => 'dQw4w9WgXcQ', 'channel' => 'acme',
        'artist' => 'acme', 'album' => 'acme', 'shop' => 'acme', 'domain' => 'acme',
    ];

    /**
     * @param  array<string, mixed>  $surface  one entry of CompiledCatalog::surfaces()
     * @param  array<string, string>  $handWritten  surface key => URL, from tests/fixtures/catalog/probe-urls.php
     */
    public static function for(array $surface, array $handWritten): ?string
    {
        $key = (string) $surface['key'];
        if (isset($handWritten[$key])) {
            return $handWritten[$key];
        }
        $template = $surface['canonical_url_template'] ?? null;
        if (! is_string($template) || $template === '') {
            return null;
        }

        return (string) preg_replace_callback('/\{([a-z_]+)\}/i', fn ($m) => self::SAMPLES[strtolower($m[1])] ?? 'acme', $template);
    }

    /** @param  array{platform:string,category:string,label:string}|null  $classified */
    public static function bucket(?array $classified): string
    {
        if ($classified === null) {
            return 'invisible';
        }

        return $classified['category'] === 'link' ? 'link-only' : 'connectable';
    }
}
