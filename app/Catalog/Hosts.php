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
            'm.facebook.com' => 'facebook.com',
            // fb.me / spoti.fi / on.soundcloud.com were aliases here until
            // FI-3 (2026-08-20). An alias rewrites only the registrable key
            // and keeps the PATH — but these hosts carry opaque short CODES,
            // not platform paths, so a lowercase code could match a profile
            // detector and mint a fake account (reproduced with
            // on.soundcloud.com in the sammy.pdf baseline). They are redirect
            // shorteners, handled by ShortLinkExpander now; wa.me and t.me
            // stay aliases because their paths ARE the identity (phone
            // number / channel name), not codes to dereference.
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
    /**
     * The SHOP subset of the tenant suffixes below. LinkProbeWorker still
     * probes these (M-9, 2026-08-21): the projector knows WHAT a tenant shop
     * host is, but StoreBrandSeeder needs the storefront's own evidence
     * (shop name, currency, origin) that only the probe fetches. Booking
     * tenants keep the probe refusal — their seeders need no evidence.
     *
     * @var list<string>
     */
    public const SHOP_TENANT_SUFFIXES = [
        'myshopify.com',
        'square.site',
        'bigcartel.com',
    ];

    public static function suffixOverrides(): array
    {
        return [
            ...self::SHOP_TENANT_SUFFIXES,
            'nowbookit.com',
            'gettimely.com',
            'setmore.com',
            'as.me', // Acuity scheduling tenant hosts
            'simplybook.me',
            'youcanbook.me',
        ];
    }
}
