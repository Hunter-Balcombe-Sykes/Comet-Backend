<?php

use App\Services\Platforms\MenuAiExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('posts a document_url-typed request body to Mistral OCR', function () {
    config(['services.mistral.key' => 'k1']);
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => 'Menu text here']]])]);

    $text = (new MenuAiExtractor)->ocrDocumentUrl('https://venue.example/menu.pdf');

    expect($text)->toBe('Menu text here');
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.mistral.ai/v1/ocr'
            && $request['document']['type'] === 'document_url'
            && $request['document']['document_url'] === 'https://venue.example/menu.pdf';
    });
});

it('returns null on a transport-level failure, matching ocrImageUrl error handling', function () {
    config(['services.mistral.key' => 'k1']);
    Http::fake(['api.mistral.ai/v1/ocr' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

    expect((new MenuAiExtractor)->ocrDocumentUrl('https://venue.example/menu.pdf'))->toBeNull();
});

it('returns null on a non-successful HTTP status', function () {
    config(['services.mistral.key' => 'k1']);
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response([], 500)]);

    expect((new MenuAiExtractor)->ocrDocumentUrl('https://venue.example/menu.pdf'))->toBeNull();
});

it('returns empty string when OCR ran but pages carry no readable text', function () {
    config(['services.mistral.key' => 'k1']);
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['pages' => []])]);

    expect((new MenuAiExtractor)->ocrDocumentUrl('https://venue.example/menu.pdf'))->toBe('');
});
