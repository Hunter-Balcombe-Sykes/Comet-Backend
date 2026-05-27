<?php

use App\Enums\EnquiryStatus;
use App\Models\Core\Site\Enquiry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\TestCase;

// Relationship assertions require a booted Laravel app (DB resolver).
uses(TestCase::class);

it('has new fillable + casts + relationships', function () {
    $enquiry = new Enquiry;

    $expected = ['user_id', 'site_id', 'name', 'email', 'phone', 'subject',
        'message', 'ip_hash', 'user_agent', 'read_at', 'email_sent_at',
        'status', 'customer_id', 'notification_id',
        'replied_at', 'archived_at', 'spam_at', 'redacted_at'];

    foreach ($expected as $field) {
        expect($enquiry->getFillable())->toContain($field);
    }

    expect($enquiry->getCasts()['status'])->toBe(EnquiryStatus::class);
    expect($enquiry->getCasts()['replied_at'])->toBe('datetime');
    expect($enquiry->getCasts()['archived_at'])->toBe('datetime');
    expect($enquiry->getCasts()['spam_at'])->toBe('datetime');
    expect($enquiry->getCasts()['redacted_at'])->toBe('datetime');

    expect($enquiry->customer())->toBeInstanceOf(BelongsTo::class);
    expect($enquiry->notification())->toBeInstanceOf(BelongsTo::class);
});
