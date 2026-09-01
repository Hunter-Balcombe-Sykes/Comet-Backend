<?php

// The publish gate on the two public READ paths.
//
// A CLAIMED owner's unpublished site must 404 — for them the publish knob IS
// the visibility switch. An UNCLAIMED pre-account build must keep rendering
// while unpublished: that is the pre-claim demo, and the gate that closed it
// ("Dark Until Claimed", ee1c22784) was reverted 2026-08-25 on owner decision.
// The two predicates are complements, not variants of each other.
//
// The same rule already lives on the ingest side in
// AnalyticsController::resolvePublishedSite(); this file pins it on the read
// side. Measured on dev 2026-09-01, 241 of 270 sites are unclaimed+unpublished,
// so dropping the isUnclaimed() term would darken the whole demo fleet — the
// carve-out cases below are what catch that.

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    // The 200 cases run the real payload builder, which reaches across blocks,
    // media, services, sections and content.* — the 404 cases short-circuit
    // before any of it, so a thin setup would only prove the gate fires.
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();
    setupSectionsTables();
    setupWorkplacesTable();
    setupIngestTables();
    setupContentTables();
    setupPreAccountBuildsTable();
    setupIntegrationConnectionsTable();
    Config::set('partna.throttle.enabled', false);
    Cache::flush();
});

/** Claimed owner (status=active), site published or not. */
function gateClaimed(string $handle, bool $published)
{
    $pro = createTenant($handle);
    if (! $published) {
        $pro->site->forceFill(['is_published' => false])->save();
    }

    return $pro;
}

/** Pre-account build: unclaimed owner, unpublished by default. */
function gateUnclaimed(string $handle, bool $published = false)
{
    $pro = createTenant($handle);
    $pro->forceFill(['status' => 'unclaimed'])->save();
    $pro->site->forceFill(['is_published' => $published])->save();

    return $pro;
}

// ── The fix: a claimed owner's unpublished site is dark ──────────────────

it('404s the profile of a CLAIMED owner whose site is unpublished', function () {
    gateClaimed('claimed-unpub', published: false);

    $this->getJson('/api/public/profiles/claimed-unpub')->assertNotFound();
});

it('404s the integrations of a CLAIMED owner whose site is unpublished', function () {
    gateClaimed('claimed-unpub-i', published: false);

    $this->getJson('/api/public/profiles/claimed-unpub-i/integrations')->assertNotFound();
});

// ── The carve-out: an unclaimed build renders while unpublished ──────────

it('still serves the profile of an UNCLAIMED build while unpublished', function () {
    gateUnclaimed('unclaimed-unpub');

    $this->getJson('/api/public/profiles/unclaimed-unpub')->assertOk();
});

it('still serves the integrations of an UNCLAIMED build while unpublished', function () {
    gateUnclaimed('unclaimed-unpub-i');

    $this->getJson('/api/public/profiles/unclaimed-unpub-i/integrations')->assertOk();
});

// ── Unaffected cases ─────────────────────────────────────────────────────

it('serves a published claimed site on both read paths', function () {
    gateClaimed('claimed-pub', published: true);

    $this->getJson('/api/public/profiles/claimed-pub')->assertOk();
    $this->getJson('/api/public/profiles/claimed-pub/integrations')->assertOk();
});

it('serves a published unclaimed site on both read paths', function () {
    gateUnclaimed('unclaimed-pub', published: true);

    $this->getJson('/api/public/profiles/unclaimed-pub')->assertOk();
    $this->getJson('/api/public/profiles/unclaimed-pub/integrations')->assertOk();
});

it('leaves an account with no site row alone — there is no publish knob to honour', function () {
    // Pre-account signup creates the user before the site; BuildStateOnWireTest
    // pins that this window still serves. The gate must not swallow it.
    $pro = createTenant('nosite');
    $pro->site->forceDelete();

    $this->getJson('/api/public/profiles/nosite')->assertOk();
    $this->getJson('/api/public/profiles/nosite/integrations')->assertOk();
});

// ── The constraint that made this a plan and not a one-liner ─────────────

it('darkens the moment an unpublished build is CLAIMED, with no write to site.sites', function () {
    // The one transition that turns the gate ON without touching site.sites, so
    // SiteObserver never fires. It survives on UserCacheService::invalidateUser's
    // bustSite catch-all, NOT on a deliberate seam — UserObserver's
    // PUBLIC_PROFILE_USER_FIELDS does not list `status`. Pinned here because
    // deleting that catch-all would silently stop the gate binding on claim.
    $pro = gateUnclaimed('claimflip');

    $this->getJson('/api/public/profiles/claimflip')->assertOk();

    $pro->forceFill(['status' => 'active'])->save();

    $this->getJson('/api/public/profiles/claimflip')->assertNotFound();
});

it('pins the states this gate deliberately does NOT change', function () {
    // Flagged out of scope in the plan, pinned so a later edit cannot drift it
    // either way unnoticed: a SUSPENDED owner's PUBLISHED site still reads 200
    // on both paths — only KV retires it. Publish state, not account state, is
    // what this gate rules on.
    $pro = createTenant('suspended-pub');
    $pro->forceFill(['status' => 'suspended'])->save();

    $this->getJson('/api/public/profiles/suspended-pub')->assertOk();
    $this->getJson('/api/public/profiles/suspended-pub/integrations')->assertOk();

    // ...but unpublished, it is dark — 'suspended' is not 'unclaimed', so the
    // claimed-owner branch applies.
    $pro->site->forceFill(['is_published' => false])->save();

    $this->getJson('/api/public/profiles/suspended-pub')->assertNotFound();
});

it('darkens on the very next request — the gate does not lag by the resolve-cache TTL', function () {
    $pro = gateClaimed('lagcheck', published: true);

    // Warms handle.resolve, which caches the gate verdict alongside the
    // timestamp for 30s.
    $this->getJson('/api/public/profiles/lagcheck')->assertOk();

    // A real save, so SiteObserver::saved fires and invalidateSitePayload()
    // deletes handle.resolve + its :stale twin. No time travel below: if the
    // gate rode a cache nothing busts, this next call would still be 200.
    $pro->site->forceFill(['is_published' => false])->save();

    $this->getJson('/api/public/profiles/lagcheck')->assertNotFound();
});
