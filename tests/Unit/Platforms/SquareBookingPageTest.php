<?php

use App\Services\Platforms\SquareBookingPage;

function squareWidgetDoc(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/square/widget-akro.json'), true);
}

it('parses merchant, location and team member out of every known URL shape', function (string $url, ?string $merchant, ?string $unit, ?string $tm) {
    expect(SquareBookingPage::parseUrl($url))->toBe(['merchant' => $merchant, 'unit' => $unit, 'teamMember' => $tm]);
})->with([
    ['https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&team_member_id=TM-qREuvGrHGnJ5Z', '7rn54rnv21ng7n', 'LAJZK7J54JGCW', 'TM-qREuvGrHGnJ5Z'],
    ['https://book.squareup.com/appointments/7rn54rnv21ng7n', '7rn54rnv21ng7n', null, null],
    ['https://app.squareup.com/appointments/book/7rn54rnv21ng7n/LAJZK7J54JGCW/start', '7rn54rnv21ng7n', 'LAJZK7J54JGCW', null],
    ['https://akro-studio.square.site/', null, null, null],
]);

it('lists the team with employee_token as the id the URL param uses', function () {
    $team = SquareBookingPage::team(squareWidgetDoc());
    $jesse = collect($team)->firstWhere('employeeId', 'TM-qREuvGrHGnJ5Z');
    expect($jesse['staffId'])->toBe('qgev4xbopoqbvs')
        ->and($jesse['displayName'])->toBe('Jesse Jensz')
        ->and($jesse['avatarUrl'])->toStartWith('https://');
    expect(SquareBookingPage::staffIdFor(squareWidgetDoc(), 'TM-qREuvGrHGnJ5Z'))->toBe('qgev4xbopoqbvs')
        ->and(SquareBookingPage::staffIdFor(squareWidgetDoc(), 'TM-nobody'))->toBeNull();
});

it('narrows services to the ones a staff member can be booked for, cheapest variation first', function () {
    $doc = squareWidgetDoc();
    $mine = SquareBookingPage::services($doc, 'qgev4xbopoqbvs');
    $names = array_column($mine, 'name');
    expect($names)->toContain('Beard Trim')->not->toContain('Buzz Cut');
    $beard = collect($mine)->firstWhere('name', 'Beard Trim');
    expect($beard['price'])->toBe(80.0)
        ->and($beard['price_qualifier'])->toBe('exact')
        ->and($beard['duration_seconds'])->toBe(1800)
        ->and($beard['currency'])->toBe('AUD')
        ->and($beard['service_id'])->toBe('JGQS7AK63SUIASWDSCTRSGVK')
        ->and($beard['category'])->toBe('Beards');
    expect(array_column($mine, 'position'))->toBe([0, 1]);
});

it('lands the whole menu with "from" pricing when no staff member is given', function () {
    $all = SquareBookingPage::services(squareWidgetDoc(), null);
    $beard = collect($all)->firstWhere('name', 'Beard Trim');
    expect(count($all))->toBe(3)
        ->and($beard['price'])->toBe(40.0)
        ->and($beard['price_qualifier'])->toBe('from');
});

it('builds the per-service deep link with the team member preselected', function () {
    expect(SquareBookingPage::bookingDeepLink('7rn54rnv21ng7n', 'LAJZK7J54JGCW', 'JGQS7AK63SUIASWDSCTRSGVK', 'TM-qREuvGrHGnJ5Z'))
        ->toBe('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services/JGQS7AK63SUIASWDSCTRSGVK?team_member_id=TM-qREuvGrHGnJ5Z');
    expect(SquareBookingPage::widgetUrl('7rn54rnv21ng7n', 'LAJZK7J54JGCW'))
        ->toBe('https://app.squareup.com/appointments/api/buyer/widget/7rn54rnv21ng7n?unit_token=LAJZK7J54JGCW');
});

it('reads the business block and the first location', function () {
    $business = SquareBookingPage::business(squareWidgetDoc());
    expect($business['name'])->toBe('Akro Studio')
        ->and($business['currency'])->toBe('AUD')
        ->and($business['instagramUrl'])->toBe('https://www.instagram.com/akro.studio/')
        ->and($business['logoUrl'])->toStartWith('https://');
    expect(SquareBookingPage::unitToken(squareWidgetDoc()))->toBe('LAJZK7J54JGCW');
});
