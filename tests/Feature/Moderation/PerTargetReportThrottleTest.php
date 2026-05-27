<?php

use App\Http\Middleware\Moderation\PerTargetReportThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

it('allows up to the configured cap per (ip, target) per window', function () {
    $mw = new PerTargetReportThrottle;
    $req = Request::create('/v1/public/report', 'POST', [
        'target_type'   => 'Site',
        'target_handle' => 'joeplumber',
    ]);
    $req->server->set('REMOTE_ADDR', '203.0.113.50');

    for ($i = 0; $i < 3; $i++) {
        $hit = $mw->handle($req, fn () => response('ok', 200));
        expect($hit->status())->toBe(200);
    }
});

it('blocks the 4th request from same (ip, target) in window', function () {
    $mw = new PerTargetReportThrottle;
    $req = Request::create('/v1/public/report', 'POST', [
        'target_type'   => 'Site',
        'target_handle' => 'joeplumber',
    ]);
    $req->server->set('REMOTE_ADDR', '203.0.113.50');

    for ($i = 0; $i < 3; $i++) {
        $mw->handle($req, fn () => response('ok', 200));
    }

    $blocked = $mw->handle($req, fn () => response('ok', 200));
    expect($blocked->status())->toBe(429);
});

it('does not block when the target is different', function () {
    $mw = new PerTargetReportThrottle;

    $req1 = Request::create('/v1/public/report', 'POST', ['target_type' => 'Site', 'target_handle' => 'a']);
    $req2 = Request::create('/v1/public/report', 'POST', ['target_type' => 'Site', 'target_handle' => 'b']);
    $req1->server->set('REMOTE_ADDR', '203.0.113.50');
    $req2->server->set('REMOTE_ADDR', '203.0.113.50');

    for ($i = 0; $i < 3; $i++) {
        $mw->handle($req1, fn () => response('ok', 200));
    }
    $other = $mw->handle($req2, fn () => response('ok', 200));
    expect($other->status())->toBe(200);
});
