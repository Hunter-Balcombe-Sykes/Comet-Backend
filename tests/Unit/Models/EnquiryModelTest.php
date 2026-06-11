<?php

use App\Models\Core\Site\Enquiry;
use Illuminate\Database\Eloquent\SoftDeletes;

it('uses the site.enquiries table', function () {
    expect((new Enquiry)->getTable())->toBe('site.enquiries');
});

it('casts read_at and timestamps to datetime', function () {
    $casts = (new Enquiry)->getCasts();

    expect($casts['read_at'])->toBe('datetime');
    expect($casts['created_at'])->toBe('datetime');
    expect($casts['updated_at'])->toBe('datetime');
    expect($casts['deleted_at'])->toBe('datetime');
});

it('uses UUID keys and soft deletes', function () {
    $model = new Enquiry;

    expect($model->incrementing)->toBeFalse();
    expect($model->getKeyType())->toBe('string');
    expect(in_array(SoftDeletes::class, class_uses_recursive($model)))->toBeTrue();
});

it('hides submitter PII and request telemetry from default serialisation', function () {
    $model = (new Enquiry)->forceFill([
        'id' => 'enq-uuid',
        'user_id' => 'pro-uuid',
        'site_id' => 'site-uuid',
        'name' => 'Jane Visitor',
        'email' => 'jane@example.com',
        'phone' => '+61400000000',
        'subject' => 'Hello',
        'message' => 'Long-form contact-form text',
        'ip_hash' => 'hash',
        'user_agent' => 'Mozilla/5.0',
    ]);

    $array = $model->toArray();
    $hiddenKeys = ['name', 'email', 'phone', 'message', 'ip_hash', 'user_agent'];
    $leaked = array_intersect($hiddenKeys, array_keys($array));

    expect($leaked)->toBe([], 'PII keys leaked through toArray(): '.implode(',', $leaked));

    // Routing-level fields the owning professional already knows must remain visible
    // so enquiry rows can still be tagged/filtered by foreign key without a Resource.
    expect($array)->toHaveKey('user_id');
    expect($array)->toHaveKey('site_id');
    expect($array)->toHaveKey('subject');
});
