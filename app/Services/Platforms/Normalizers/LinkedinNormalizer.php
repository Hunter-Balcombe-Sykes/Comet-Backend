<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// LinkedIn link-only normalizer — ported verbatim from the former LinkedinController.
// Accepts linkedin.com/in|company|school|pub/<slug> URLs (any scheme or none,
// locale subdomains, trailing junk tolerated) or a bare slug (assumed personal)
// → {username, url}. Legacy /pub/ profiles are stored as the modern /in/ form.
class LinkedinNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~linkedin\.com/(in|company|school|pub)/([^/?#]+)~i', $s, $m)) {
            // Legacy /pub/ profiles redirect to /in/ — store the modern form.
            $kind = strtolower($m[1]) === 'pub' ? 'in' : strtolower($m[1]);
            $slug = rawurldecode($m[2]);
        } elseif (str_contains($s, 'linkedin.com')) {
            // A linkedin.com URL without a recognised profile path — reject
            // rather than storing a feed/jobs link.
            return null;
        } else {
            $kind = 'in';
            $slug = PlatformInput::token($s);
        }

        if (! preg_match('~^[\p{L}\p{N}._\-]{2,100}$~u', $slug)) {
            return null;
        }

        return [
            'username' => $slug,
            'url' => 'https://www.linkedin.com/'.$kind.'/'.rawurlencode($slug).'/',
        ];
    }
}
