<?php

// T27c (2026-08-28): TikTok profile feed → watch pool. Fixture mirrors the
// live clockworks~tiktok-profile-scraper capture (2026-08-28).

use App\Ingest\Connectors\TiktokConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\TiktokVideoProjector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function ttIo(array $effect): Io
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

function ttVideo(string $id, string $iso, bool $pinned = false): array
{
    return [
        'id' => $id,
        'text' => 'Its curry in a hurry from #NextLevelKitchen !'."\n".'Enjoy the recipe.',
        'createTimeISO' => $iso,
        'webVideoUrl' => "https://www.tiktok.com/@gordonramsayofficial/video/{$id}",
        'isPinned' => $pinned,
        'videoMeta' => [
            'height' => 1022,
            'width' => 576,
            'duration' => 87,
            'coverUrl' => "https://p16-common-sign.tiktokcdn-us.com/{$id}.image?x-expires=1788022800&x-signature=abc",
        ],
    ];
}

function ttPull(): Pull
{
    return new Pull(identifier: '@GordonRamsayOfficial', stream: TiktokConnector::manifest()->stream('videos'), config: []);
}

it('lands videos ordered by recency with pinned posts demoted', function () {
    $io = ttIo(['status' => 'ok', 'cached' => false, 'data' => [
        ttVideo('7000000000000000001', '2024-01-01T00:00:00.000Z', pinned: true),
        ttVideo('7341108653442829601', '2024-02-29T19:31:02.000Z'),
    ]]);

    $messages = iterator_to_array((new TiktokConnector)->pull(ttPull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($io->calls)->toBe([['actor', 'tiktok', ['username' => 'gordonramsayofficial']]])
        ->and($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('7341108653442829601')
        ->and($records[0]->doc['duration'])->toBe(87)
        ->and($records[0]->doc['cover'])->toContain('x-expires')
        ->and($covered)->not->toBeNull();
});

it('emits a Note and no coverage when the actor result has no videos', function () {
    $messages = iterator_to_array((new TiktokConnector)->pull(ttPull(), ttIo(['status' => 'ok', 'cached' => false, 'data' => []])), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Note))->not->toBeNull()
        ->and(collect($messages)->first(fn ($m) => $m instanceof Covered))->toBeNull();
});

it('folds a refused effect into Unavailable', function () {
    $messages = iterator_to_array((new TiktokConnector)->pull(ttPull(), ttIo(['status' => 'refused', 'cached' => false, 'data' => null])), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
});

it('projects a video with owned cover ref, embed key and caption headline', function () {
    expect(ProjectorRegistry::has('tiktok', 'videos'))->toBeTrue();

    $p = (new TiktokVideoProjector)->project(new RecordView([
        'id' => '7341108653442829601',
        'caption' => "Its curry in a hurry\nsecond line",
        'created_at' => '2024-02-29T19:31:02.000Z',
        'url' => 'https://www.tiktok.com/@gordonramsayofficial/video/7341108653442829601',
        'cover' => 'https://p16.tiktokcdn-us.com/x.image?x-expires=1',
        'duration' => 87,
    ]));

    expect($p['headline'])->toBe('Its curry in a hurry')
        ->and($p['facets']['f_embed'])->toBe(['provider' => 'tiktok', 'embed_key' => '7341108653442829601'])
        ->and($p['facets']['f_duration']['seconds'])->toBe(87)
        ->and($p['media'][0]['ref'])->toBe('tiktok:7341108653442829601:cover');
});
