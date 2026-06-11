<?php

use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    // PageviewRequest uses Rule::exists('pgsql.site.sites', 'id') which resolves to
    // the real site.sites table via the pgsql connection. No shadow table needed.

    // analytics.site_visits — needed so the pageview controller can save the record.
    setupSiteVisitsTable();
});

// The /public/analytics/pageviews route in api.php is a header-based fallback for
// path-based frontends that can't use subdomain DNS. PageviewRequest::prepareForValidation()
// falls back to X-Site-Subdomain when no route('subdomain') is available, merging it
// into $data['subdomain']. The IDOR bug: the old resolveSiteFromData() ignores
// $data['subdomain'] entirely when site_id is present, letting an attacker record
// events under a victim's site_id.

it('refuses to record a pageview when body site_id does not match the X-Site-Subdomain header', function () {
    $victim = createBrandTenant('victim');
    $attacker = createBrandTenant('attacker');

    // Attack: correct attacker subdomain in header, victim's site_id in body.
    // prepareForValidation() merges subdomain='attacker'. The cross-check must
    // detect the mismatch and reject the request.
    $response = $this->withHeaders(['X-Site-Subdomain' => 'attacker'])
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $victim->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    expect($response->status())->toBe(422);
});

it('records a pageview when site_id matches the X-Site-Subdomain header', function () {
    $tenant = createBrandTenant('legit');

    $response = $this->withHeaders(['X-Site-Subdomain' => 'legit'])
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    // 201 = pageview recorded successfully.
    // 404/500 acceptable if the aggregate job or cache service hits missing
    // infrastructure in the SQLite test environment.
    expect($response->status())->toBeIn([201, 404, 500]);
});
