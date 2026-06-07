<?php

use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use Illuminate\Http\Request;

/**
 * P2-35: the public lead/enquiry/subscribe endpoints resolve the tenant from the
 * client-supplied X-Site-Subdomain header. A browser also sends an UNforgeable
 * Origin; when it names a genuine tenant mini-site we trust it over the header,
 * so a page on attacker.partna.au can't inject into another tenant by spoofing
 * the header. Requests with no Origin (server/proxy/SSR) or a reserved/first-party
 * Origin fall through to the existing resolution untouched.
 */
function subdomainResolver(): object
{
    return new class
    {
        use ResolvesSubdomainFromHost;

        public function resolve(Request $request): ?string
        {
            return $this->resolveSiteSubdomain($request);
        }
    };
}

function requestWith(array $headers): Request
{
    config(['partna.public_domain' => 'partna.au']);
    $request = Request::create('/api/public/enquiry', 'POST');
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

it('ignores a spoofed X-Site-Subdomain when the Origin is a different mini-site', function () {
    $subdomain = subdomainResolver()->resolve(requestWith([
        'Origin' => 'https://attacker.partna.au',
        'X-Site-Subdomain' => 'victim',
    ]));

    expect($subdomain)->toBe('attacker');
});

it('falls back to the header when there is no Origin (server/proxy call)', function () {
    $subdomain = subdomainResolver()->resolve(requestWith([
        'X-Site-Subdomain' => 'realhandle',
    ]));

    expect($subdomain)->toBe('realhandle');
});

it('does not let a reserved/first-party Origin override the header', function () {
    $subdomain = subdomainResolver()->resolve(requestWith([
        'Origin' => 'https://app.partna.au', // 'app' is a reserved subdomain
        'X-Site-Subdomain' => 'realhandle',
    ]));

    expect($subdomain)->toBe('realhandle');
});

it('resolves to the shared handle when Origin and header agree', function () {
    $subdomain = subdomainResolver()->resolve(requestWith([
        'Origin' => 'https://acme.partna.au',
        'X-Site-Subdomain' => 'acme',
    ]));

    expect($subdomain)->toBe('acme');
});

it('ignores a nested-subdomain Origin and falls through to the header', function () {
    $subdomain = subdomainResolver()->resolve(requestWith([
        'Origin' => 'https://evil.acme.partna.au',
        'X-Site-Subdomain' => 'realhandle',
    ]));

    expect($subdomain)->toBe('realhandle');
});
