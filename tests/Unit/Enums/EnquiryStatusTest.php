<?php

use App\Enums\EnquiryStatus;

it('exposes all five statuses', function () {
    expect(EnquiryStatus::cases())->toHaveCount(5);
    expect(EnquiryStatus::New->value)->toBe('new');
    expect(EnquiryStatus::Read->value)->toBe('read');
    expect(EnquiryStatus::Replied->value)->toBe('replied');
    expect(EnquiryStatus::Archived->value)->toBe('archived');
    expect(EnquiryStatus::Spam->value)->toBe('spam');
});

it('can be cast from string', function () {
    expect(EnquiryStatus::from('new'))->toBe(EnquiryStatus::New);
});
