<?php

namespace App\Services\Notifications\Adapters;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;

interface EnquiryNotificationAdapter
{
    public function channel(): string;  // 'in_app' | 'email' | ...

    public function dispatch(Enquiry $enquiry, Block $block): void;
}
