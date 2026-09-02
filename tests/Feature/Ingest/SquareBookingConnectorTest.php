<?php

use App\Ingest\Connectors\SquareBookingConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\SquareServiceProjector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function squareBookIo(array $response): Io
{
    return new class($response) implements Io
    {
        public array $gets = [];

        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
            $this->gets[] = ['url' => $url, 'headers' => $headers];

            return $this->response;
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

function squareBookPage(): array
{
    return ['status' => 200, 'headers' => [], 'body' => file_get_contents(dirname(__DIR__, 2).'/fixtures/square/widget-akro.json')];
}

function squareBookPull(string $identifier): Pull
{
    return new Pull(identifier: $identifier, stream: SquareBookingConnector::manifest()->stream('services'), config: []);
}

const JESSE_URL = 'https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW?team_member_id=TM-qREuvGrHGnJ5Z';

it('asks the widget endpoint for JSON and lands only the team member\'s services with deep links', function () {
    $io = squareBookIo(squareBookPage());
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull(JESSE_URL), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->gets[0]['url'])->toBe('https://app.squareup.com/appointments/api/buyer/widget/7rn54rnv21ng7n?unit_token=LAJZK7J54JGCW')
        ->and($io->gets[0]['headers']['Accept'] ?? null)->toBe('application/json');
    expect(array_column(array_map(fn ($r) => $r->doc, $records), 'name'))->toBe(['Beard Trim', 'Haircut and Style']);
    expect($records[0]->key)->toBe('JGQS7AK63SUIASWDSCTRSGVK')
        ->and($records[0]->doc['price'])->toBe(80.0)
        ->and($records[0]->doc['duration_seconds'])->toBe(1800)
        ->and($records[0]->doc['url'])->toBe('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services/JGQS7AK63SUIASWDSCTRSGVK?team_member_id=TM-qREuvGrHGnJ5Z');
    expect(collect($messages)->first(fn ($m) => $m instanceof Covered))->not->toBeNull();
});

it('lands the whole menu when the url names no team member', function () {
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW'), squareBookIo(squareBookPage())), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(3)
        ->and($records[0]->doc['price_qualifier'])->toBe('from')
        ->and($records[0]->doc['url'])->not->toContain('team_member_id');
});

it('notes an unknown team member and falls back to the whole menu', function () {
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW?team_member_id=TM-nobodyhere'), squareBookIo(squareBookPage())), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Note)?->code)->toBe('team_member_not_found');
    expect(array_filter($messages, fn ($m) => $m instanceof Record))->toHaveCount(3);
});

it('reports unavailable when the endpoint answers with the HTML page instead of JSON', function () {
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull(JESSE_URL), squareBookIo(['status' => 200, 'headers' => [], 'body' => '<!doctype html><html><body>Book</body></html>'])), false);

    expect($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('reports unavailable when the url carries no merchant id', function () {
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull('https://akro-studio.square.site/'), squareBookIo(squareBookPage())), false);

    expect($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('projects a service record into the service kind with offer, duration and link', function () {
    $projector = new SquareServiceProjector;
    $view = new RecordView([
        'service_id' => 'JGQS7AK63SUIASWDSCTRSGVK', 'name' => 'Beard Trim', 'price' => 80.0, 'price_qualifier' => 'exact',
        'currency' => 'AUD', 'duration_seconds' => 1800, 'url' => 'https://book.squareup.com/appointments/x/location/y/services/z',
    ], 'JGQS7AK63SUIASWDSCTRSGVK');

    $item = $projector->project($view);

    expect($item['kind'])->toBe('service')
        ->and($item['headline'])->toBe('Beard Trim')
        ->and($item['offers'][0])->toMatchArray(['channel' => 'square', 'qualifier' => 'exact', 'amount_minor' => 8000, 'currency' => 'AUD'])
        ->and($item['facets']['f_duration']['seconds'])->toBe(1800)
        ->and($item['facets']['f_link']['url'])->toContain('/services/z');
    expect(ProjectorRegistry::for('square_book', 'services'))->toBeInstanceOf(SquareServiceProjector::class);
});
