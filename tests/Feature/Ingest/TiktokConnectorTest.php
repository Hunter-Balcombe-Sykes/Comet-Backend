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
            // A signature still good for the mirror's queue hop — an expired
            // or .heic cover takes the oEmbed refresh path (its own tests below).
            'coverUrl' => "https://p16-common-sign.tiktokcdn-us.com/{$id}.image?x-expires=4102444800&x-signature=abc",
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

function ttIoWithOembed(array $effect, array|Throwable $oembed): Io
{
    return new class($effect, $oembed) implements Io
    {
        public array $fetched = [];

        public function __construct(private array $effect, private array|Throwable $oembed) {}

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
            $this->fetched = array_merge($this->fetched, $urls);
            if ($this->oembed instanceof Throwable) {
                throw $this->oembed;
            }
            $out = [];
            foreach ($urls as $url) {
                $out[$url] = isset($this->oembed[$url])
                    ? ['status' => 200, 'body' => json_encode(['thumbnail_url' => $this->oembed[$url]]), 'headers' => []]
                    : null;
            }

            return $out;
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return $this->effect;
        }
    };
}

it('refreshes an expired or .heic cover through TikTok oEmbed (2026-09-05, st_ali: 25 of 30 covers unservable)', function () {
    $expired = ttVideo('7000000000000000001', '2024-01-01T00:00:00.000Z');
    $expired['videoMeta']['coverUrl'] = 'https://p16-common-sign.tiktokcdn-eu.com/tos-maliva-p-85c255/aaa~tplv-tiktokx-shrink-aq:360:360:q75.heic?x-expires='.(now()->getTimestamp() - 60).'&x-signature=old';
    $fresh = ttVideo('7341108653442829601', '2024-02-29T19:31:02.000Z');
    $heic = ttVideo('7341108653442829602', '2024-03-01T00:00:00.000Z');
    $heic['videoMeta']['coverUrl'] = 'https://p16-common-sign.tiktokcdn-eu.com/tos-alisg-p-0037/bbb~tplv-tiktokx-shrink-aq:360:360:q75.heic?x-expires=4102444800&x-signature=new';

    $oembedFor = fn (string $id) => 'https://www.tiktok.com/oembed?url='.rawurlencode("https://www.tiktok.com/@gordonramsayofficial/video/{$id}");
    $io = ttIoWithOembed(['status' => 'ok', 'cached' => false, 'data' => [$expired, $fresh, $heic]], [
        $oembedFor('7000000000000000001') => 'https://p16-common-sign.tiktokcdn.com/tos-alisg-p-0037/aaa~tplv-tiktokx-origin.image?x-expires=4102444800&x-signature=fresh1',
        $oembedFor('7341108653442829602') => 'https://p16-common-sign.tiktokcdn.com/tos-alisg-p-0037/bbb~tplv-tiktokx-origin.image?x-expires=4102444800&x-signature=fresh2',
    ]);

    $records = array_values(array_filter(iterator_to_array((new TiktokConnector)->pull(ttPull(), $io), false), fn ($m) => $m instanceof Record));
    $byKey = collect($records)->keyBy('key');

    expect($io->fetched)->toHaveCount(2)
        ->and($io->fetched)->not->toContain($oembedFor('7341108653442829601'))
        ->and($byKey['7000000000000000001']->doc['cover'])->toContain('x-signature=fresh1')
        ->and($byKey['7341108653442829602']->doc['cover'])->toContain('x-signature=fresh2')
        ->and($byKey['7341108653442829601']->doc['cover'])->toContain('x-signature=abc');
});

it('keeps the vendor cover when the oEmbed refresh fails', function () {
    $expired = ttVideo('7000000000000000001', '2024-01-01T00:00:00.000Z');
    $expired['videoMeta']['coverUrl'] = 'https://p16-common-sign.tiktokcdn-eu.com/x.heic?x-expires=1&x-signature=old';
    $io = ttIoWithOembed(['status' => 'ok', 'cached' => false, 'data' => [$expired]], new RuntimeException('oembed down'));

    $records = array_values(array_filter(iterator_to_array((new TiktokConnector)->pull(ttPull(), $io), false), fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['cover'])->toContain('x-signature=old');
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
