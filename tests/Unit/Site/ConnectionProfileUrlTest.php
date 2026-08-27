<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Site\Actions\ConnectionProfileUrl;

/**
 * T25 (owner, 2026-08-28): the fallback Book action for an EMPLOYEE-mode
 * Fresha connection must preselect the staff member — venue root only for
 * storewide/unselected. Uses an unsaved model so no DB tables are needed
 * (`platform` is a generated column; the resolver reads it, so the test
 * builds connections through a stub that pins it).
 */
function cpuConn(string $platform, array $payload): IntegrationConnection
{
    $conn = new class extends IntegrationConnection
    {
        public string $stubPlatform = '';

        public function getAttribute($key)
        {
            if ($key === 'platform') {
                return $this->stubPlatform;
            }

            return parent::getAttribute($key);
        }
    };
    $conn->stubPlatform = $platform;
    $conn->payload = $payload;

    return $conn;
}

it('sends an employee-mode fresha connection to the preselected booking flow', function () {
    $url = ConnectionProfileUrl::for(cpuConn('fresha', [
        'url' => 'https://www.fresha.com/a/star-barber-darwin',
        'selection' => ['mode' => 'employee', 'employee' => ['employeeId' => 'emp-123', 'displayName' => 'Emma']],
    ]));

    expect($url)->toBe('https://www.fresha.com/a/star-barber-darwin/booking?employeeId=emp-123');
});

it('keeps the canonical venue root for storewide and unselected fresha connections', function () {
    $storewide = ConnectionProfileUrl::for(cpuConn('fresha', [
        'url' => 'https://www.fresha.com/a/star-barber-darwin',
        'selection' => ['mode' => 'storewide'],
    ]));
    $unselected = ConnectionProfileUrl::for(cpuConn('fresha', [
        'url' => 'https://www.fresha.com/a/star-barber-darwin',
        'selection' => null,
    ]));

    expect($storewide)->toBe('https://www.fresha.com/a/star-barber-darwin')
        ->and($unselected)->toBe('https://www.fresha.com/a/star-barber-darwin');
});

it('falls back to the venue root when the employee selection lacks an id', function () {
    $url = ConnectionProfileUrl::for(cpuConn('fresha', [
        'url' => 'https://www.fresha.com/a/star-barber-darwin',
        'selection' => ['mode' => 'employee', 'employee' => ['displayName' => 'Emma']],
    ]));

    expect($url)->toBe('https://www.fresha.com/a/star-barber-darwin');
});

it('leaves other platforms on the plain payload url', function () {
    expect(ConnectionProfileUrl::for(cpuConn('timely', ['url' => 'https://book.gettimely.com/x'])))
        ->toBe('https://book.gettimely.com/x');
});
