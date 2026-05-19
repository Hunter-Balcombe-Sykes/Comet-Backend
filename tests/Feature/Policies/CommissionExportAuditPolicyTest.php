<?php

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use App\Policies\CommissionExportAuditPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new CommissionExportAuditPolicy;
});

it('view: owner can view their own export audit', function () {
    $pro = (new Professional)->forceFill(['id' => 'p-1']);
    $audit = (new CommissionExportAudit)->forceFill(['id' => 'a-1', 'professional_id' => 'p-1']);

    expect($this->policy->view($pro, $audit))->toBeTrue();
});

it('view: non-owner gets 404-as-deny (no resource-existence leak)', function () {
    $pro = (new Professional)->forceFill(['id' => 'p-1']);
    $audit = (new CommissionExportAudit)->forceFill(['id' => 'a-1', 'professional_id' => 'p-2']);

    $result = $this->policy->view($pro, $audit);

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->denied())->toBeTrue()
        ->and($result->status())->toBe(404);
});
