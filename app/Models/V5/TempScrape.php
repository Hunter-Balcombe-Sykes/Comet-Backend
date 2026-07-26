<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TempScrape extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.temp_scrapes';

    protected $fillable = [
        'user_id', 'scrape_type', 'source_url', 'scraped_urls', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'scraped_urls' => 'json',
            'processed_at' => 'datetime',
        ];
    }
}
