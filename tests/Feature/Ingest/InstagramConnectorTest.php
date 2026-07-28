<?php

use App\Ingest\Connectors\InstagramConnector;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded actor results. The
// fixture mirrors today's figue actor output (raw GraphQL snake_case) with
// the camelCase drift the production scraper learned to tolerate.

/** A minimal Io whose effect() answers from a fixed verdict. */
function instagramIo(array $effectResult): Io
{
    return new class($effectResult) implements Io
    {
        public array $effects = [];

        public function __construct(private array $effectResult) {}

        public function get(string $url, array $headers = []): array
        {
            // hosts: [] — any HTTP GET is a contract violation.
            throw new EffectRefused('instagram connector must not fetch over HTTP');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new EffectRefused('instagram connector must not fetch over HTTP');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new EffectRefused('instagram connector must not fetch over HTTP');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $this->effects[] = ['kind' => $kind, 'name' => $name, 'input' => $input];

            return $this->effectResult;
        }
    };
}

function instagramPull(string $stream): Pull
{
    return new Pull(
        identifier: '@Some.Studio',
        stream: InstagramConnector::manifest()->stream($stream),
    );
}

function instagramActorProfile(): array
{
    return [
        'username' => 'some.studio',
        'full_name' => 'Some Studio',
        'biography' => 'Cuts and colour. Bookings via link.',
        'profile_pic_url_hd' => 'https://scontent.cdninstagram.com/v/avatar_hd.jpg?sig=abc',
        'followersCount' => 12840,
        'latestPosts' => [
            [
                // Pinned but OLD — must not win the top slot.
                'shortCode' => 'Cpinned01',
                'type' => 'Image',
                'caption' => 'Our studio turns five.',
                'timestamp' => '2026-01-05T10:00:00Z',
                'display_url' => 'https://scontent.cdninstagram.com/v/pinned.jpg?sig=x',
                'isPinned' => true,
            ],
            [
                'shortCode' => 'Cnewest99',
                'type' => 'Sidecar',
                'caption' => 'Before and after, winter balayage.',
                'timestamp' => '2026-07-20T03:15:00Z',
                'display_url' => 'https://scontent.cdninstagram.com/v/car0.jpg?sig=a',
                'childPosts' => [
                    ['display_url' => 'https://scontent.cdninstagram.com/v/car0.jpg?sig=a'],
                    ['display_url' => 'https://scontent.cdninstagram.com/v/car1.jpg?sig=b'],
                    ['display_url' => 'https://scontent.cdninstagram.com/v/car2.jpg?sig=c'],
                ],
            ],
            [
                'shortCode' => 'Cvideo55',
                'type' => 'Video',
                'caption' => 'Fresh fade, 60 seconds.',
                'taken_at_timestamp' => 1778112000,
                'display_url' => 'https://scontent.cdninstagram.com/v/poster.jpg?sig=d',
                'video_url' => 'https://scontent.cdninstagram.com/v/clip.mp4?sig=e',
            ],
        ],
    ];
}

it('describes exactly one ledgered actor effect and never touches http', function () {
    $io = instagramIo(['status' => 'ok', 'cached' => false, 'data' => [instagramActorProfile()]]);

    iterator_to_array((new InstagramConnector)->pull(instagramPull('media'), $io));

    expect($io->effects)->toHaveCount(1)
        ->and($io->effects[0]['kind'])->toBe('actor')
        ->and($io->effects[0]['name'])->toBe('instagram')
        ->and($io->effects[0]['input']['username'])->toBe('some.studio');
});

it('lands the profile stream as one exhaustive identity record', function () {
    $io = instagramIo(['status' => 'ok', 'cached' => false, 'data' => [instagramActorProfile()]]);

    $messages = iterator_to_array((new InstagramConnector)->pull(instagramPull('profile'), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['username'])->toBe('some.studio')
        ->and($records[0]->doc['full_name'])->toBe('Some Studio')
        ->and($records[0]->doc['followers'])->toBe(12840)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('lands posts newest-first with a carousel as ONE record carrying every frame', function () {
    $io = instagramIo(['status' => 'ok', 'cached' => false, 'data' => [instagramActorProfile()]]);

    $messages = iterator_to_array((new InstagramConnector)->pull(instagramPull('media'), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(3)
        // Recency beats pinned array order.
        ->and($records[0]->key)->toBe('Cnewest99')
        ->and($records[0]->doc['images'])->toHaveCount(3)
        ->and($records[1]->key)->toBe('Cvideo55')
        // Epoch seconds normalise to ISO deterministically.
        ->and($records[1]->doc['taken_at'])->toBe(gmdate('Y-m-d\TH:i:s\Z', 1778112000))
        ->and($records[1]->doc['video_url'])->toContain('clip.mp4')
        ->and($records[2]->key)->toBe('Cpinned01')
        // The grid is a window, never the whole account.
        ->and($covered->coverage->toArray()['type'])->toBe('prefix');
});

it('folds a refused or failed effect into unavailable — the budget doing its job', function () {
    foreach (['refused', 'abandoned', 'failed'] as $status) {
        $io = instagramIo(['status' => $status, 'cached' => true, 'data' => null]);
        $messages = iterator_to_array((new InstagramConnector)->pull(instagramPull('media'), $io));

        expect($messages)->toHaveCount(1)
            ->and($messages[0])->toBeInstanceOf(Unavailable::class);
    }
});

it('treats an error-shaped actor item as unavailable, never an emptied account', function () {
    $io = instagramIo(['status' => 'ok', 'cached' => false, 'data' => [[
        'username' => 'some.studio', 'url' => 'https://instagram.com/some.studio',
        'scrapedAt' => '2026-07-28T00:00:00Z', 'error' => 'not_found',
    ]]]);

    $messages = iterator_to_array((new InstagramConnector)->pull(instagramPull('media'), $io));

    expect($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('is actor-billed, so the provisioner keeps it unscheduled — manual-only by construction', function () {
    expect(InstagramConnector::manifest()->cost)->toBe(CostClass::Actor)
        ->and(InstagramConnector::manifest()->hosts)->toBe([]);
});

it('declares media as a taken_at feed and profile as identity with no authoritative fields yet', function () {
    $media = InstagramConnector::manifest()->stream('media');
    $profile = InstagramConnector::manifest()->stream('profile');

    expect($media->profile)->toBe(SourceProfile::Feed)
        ->and($media->orderField)->toBe('taken_at')
        ->and($profile->profile)->toBe(SourceProfile::Identity)
        ->and($profile->target)->toBe('profile_fields')
        ->and($profile->authoritativeFields)->toBe([]);
});
