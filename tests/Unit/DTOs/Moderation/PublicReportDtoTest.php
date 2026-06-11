<?php

use App\DTOs\Moderation\PublicReportDto;

it('exposes immutable typed fields', function () {
    $dto = new PublicReportDto(
        targetType: 'Site',
        targetHandle: 'joeplumber',
        reasonCode: 'harassment',
        details: 'Long-form complaint',
        reporterEmail: 'reporter@example.com',
        reporterIp: '203.0.113.42',
    );

    expect($dto->targetType)->toBe('Site');
    expect($dto->targetHandle)->toBe('joeplumber');
    expect($dto->reasonCode)->toBe('harassment');
    expect($dto->details)->toBe('Long-form complaint');
    expect($dto->reporterEmail)->toBe('reporter@example.com');
    expect($dto->reporterIp)->toBe('203.0.113.42');
});

it('allows nullable details and reporter_email', function () {
    $dto = new PublicReportDto('Site', 'h', 'spam', null, null, '127.0.0.1');
    expect($dto->details)->toBeNull();
    expect($dto->reporterEmail)->toBeNull();
});
