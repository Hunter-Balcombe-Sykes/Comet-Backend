<?php

use App\Services\Shop\DiscountCodeSniffer;

it('reads a discount code out of a link tile title, never prose', function () {
    expect(DiscountCodeSniffer::sniff('Gamma+ - CODE: TEEGAN10'))->toBe('TEEGAN10')
        ->and(DiscountCodeSniffer::sniff('use code JORDAN15 for 15% off'))->toBe('JORDAN15')
        ->and(DiscountCodeSniffer::sniff('Promo: BARBER-20'))->toBe('BARBER-20')
        ->and(DiscountCodeSniffer::sniff('Discount code teegan10'))->toBe('TEEGAN10')
        ->and(DiscountCodeSniffer::sniff('Jukes Grooming'))->toBeNull()
        ->and(DiscountCodeSniffer::sniff('code for members only'))->toBeNull()
        ->and(DiscountCodeSniffer::sniff(''))->toBeNull()
        ->and(DiscountCodeSniffer::sniff(null))->toBeNull();
});
