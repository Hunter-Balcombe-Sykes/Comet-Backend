<?php

// T27c (2026-08-28): Facebook page posts → media pool. Fixture mirrors the
// live apify~facebook-posts-scraper capture (2026-08-28).

use App\Ingest\Connectors\FacebookConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\FacebookMediaProjector;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function fbIo(array $effect): Io
{
    return new class($effect) implements Io
    {
        public array $calls = [];

        public function __construct(private array $effect) {}

        public function get(string $url, array $headers = []): array
        {
            throw new RuntimeException('unexpected GET');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new RuntimeException('unexpected getMany');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $this->calls[] = [$kind, $name, $input];

            return $this->effect;
        }
    };
}

function fbPost(string $postId, string $time, array $media = [], ?array $sharedPost = null): array
{
    return array_filter([
        'postId' => $postId,
        'url' => "https://www.facebook.com/NASA/posts/{$postId}",
        'time' => $time,
        'timestamp' => 1787836202,
        'text' => 'Liftoff.',
        'media' => $media,
        'sharedPost' => $sharedPost,
    ], static fn ($v) => $v !== null);
}

function fbPhoto(string $uri): array
{
    return [
        '__typename' => 'Photo',
        'thumbnail' => $uri.'&thumb=1',
        'photo_image' => ['uri' => $uri],
    ];
}

function fbPull(): Pull
{
    return new Pull(identifier: 'https://www.facebook.com/nasa', stream: FacebookConnector::manifest()->stream('media'), config: []);
}

it('lands imagery posts as media and skips text-only posts and bare reshares', function () {
    $io = fbIo(['status' => 'ok', 'cached' => false, 'data' => [
        fbPost('100', '2026-08-01T00:00:00.000Z', [fbPhoto('https://scontent.xx.fbcdn.net/a.png?oe=6A9660A3'), fbPhoto('https://scontent.xx.fbcdn.net/b.png?oe=6A9660A3')]),
        fbPost('200', '2026-08-27T13:10:02.000Z', [fbPhoto('https://scontent.xx.fbcdn.net/c.png?oe=6A9660A3')]),
        fbPost('300', '2026-08-20T00:00:00.000Z'),
        fbPost('400', '2026-08-21T00:00:00.000Z', [], ['postId' => 'other']),
    ]]);

    $messages = iterator_to_array((new FacebookConnector)->pull(fbPull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->calls)->toBe([['actor', 'facebook', ['page_url' => 'https://www.facebook.com/nasa']]])
        ->and($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('200')
        ->and($records[1]->doc['images'])->toHaveCount(2)
        ->and(collect($messages)->first(fn ($m) => $m instanceof Covered))->not->toBeNull();
});

it('emits a Note and no coverage for a page with no own-imagery posts', function () {
    $io = fbIo(['status' => 'ok', 'cached' => false, 'data' => [fbPost('300', '2026-08-20T00:00:00.000Z')]]);
    $messages = iterator_to_array((new FacebookConnector)->pull(fbPull(), $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Note))->not->toBeNull()
        ->and(collect($messages)->first(fn ($m) => $m instanceof Covered))->toBeNull();
});

it('refuses a non-facebook identifier without spending the effect', function () {
    $io = fbIo(['status' => 'ok', 'cached' => false, 'data' => []]);
    $pull = new Pull(identifier: 'https://evil.example/nasa', stream: FacebookConnector::manifest()->stream('media'), config: []);
    $messages = iterator_to_array((new FacebookConnector)->pull($pull, $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull()
        ->and($io->calls)->toBe([]);
});

it('projects a post as one item whose gallery rows carry owned refs', function () {
    expect(ProjectorRegistry::has('facebook', 'media'))->toBeTrue();

    $p = (new FacebookMediaProjector)->project(new RecordView([
        'post_id' => '1613892763439427',
        'url' => 'https://www.facebook.com/NASA/posts/pfbid02k',
        'text' => 'Liftoff.',
        'published_at' => '2026-08-27T13:10:02.000Z',
        'images' => [['url' => 'https://scontent.xx.fbcdn.net/a.png?oe=1'], ['url' => 'https://scontent.xx.fbcdn.net/b.png?oe=1']],
    ]));

    expect($p['media'])->toHaveCount(2)
        ->and($p['media'][0])->toMatchArray(['role' => 'cover', 'ref' => 'facebook:1613892763439427:0'])
        ->and($p['media'][1]['role'])->toBe('gallery')
        ->and($p['facets']['f_text']['body'])->toBe('Liftoff.');
});
