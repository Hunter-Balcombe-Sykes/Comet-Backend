<?php

namespace App\Services\Moderation;

final class ContentReportSubmitResult
{
    public function __construct(
        public readonly string $receiptId,
    ) {}
}
