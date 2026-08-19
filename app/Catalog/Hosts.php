<?php

namespace App\Catalog;

/**
 * Host-level catalog data that is not per-surface: alias hosts that should
 * evaluate another registrable key's detectors, and multi-tenant suffixes
 * where the tenant label (not the eTLD+1) is the identity boundary.
 */
class Hosts
{
    /** @return array<string, string> alias host => registrable key */
    public static function aliases(): array
    {
        return [
            'youtu.be' => 'youtube.com',
            'music.youtube.com' => 'youtube.com',
            'wa.me' => 'whatsapp.com',
            't.me' => 'telegram.org',
            'fb.me' => 'facebook.com',
            'm.facebook.com' => 'facebook.com',
            'spoti.fi' => 'spotify.com',
            'on.soundcloud.com' => 'soundcloud.com',
            // RA's legacy domain still resolves and circulates in old bios;
            // its links are the same pages ra.co serves (events-parity).
            'residentadvisor.net' => 'ra.co',
            // Luma rebranded onto luma.com — lu.ma now 301s there, and the
            // event pages people copy carry the new host (found live,
            // events-parity 2026-08-19). Same pages, same path grammar.
            'luma.com' => 'lu.ma',
        ];
    }

    /**
     * Suffixes treated as registrable boundaries themselves: `acme.myshopify.com`
     * identifies tenant `acme`, so PSL eTLD+1 (`myshopify.com`) alone would
     * collapse every store into one key.
     *
     * @return list<string>
     */
    public static function suffixOverrides(): array
    {
        return [
            'myshopify.com',
            'square.site',
            'bigcartel.com',
            'nowbookit.com',
            'gettimely.com',
            'setmore.com',
            'as.me', // Acuity scheduling tenant hosts
            'simplybook.me',
            'youcanbook.me',
        ];
    }
}
