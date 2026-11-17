<?php

use App\Enums\GenerationStatus;
use App\Services\Pixelforge\ProviderGeneration;

it('parses the documented succeeded generation', function () {
    $snapshot = ProviderGeneration::fromApi(pixelforgeGeneration('succeeded', ['metadata' => ['generation_id' => '01ABC']]));

    expect($snapshot->id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($snapshot->status)->toBe(GenerationStatus::Succeeded)
        ->and($snapshot->localGenerationId)->toBe('01ABC')
        ->and($snapshot->usage['reasoning_tokens'])->toBe(3184)
        ->and($snapshot->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($snapshot->completedAt?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
});

it('parses a failure reason', function () {
    $snapshot = ProviderGeneration::fromApi(pixelforgeGeneration('failed'));

    expect($snapshot->errorCode)->toBe('internal_error')
        ->and($snapshot->errorMessage)->toContain('not charged');
});

it('only accepts https image URLs, so nothing like javascript: can reach our pages', function (string $url) {
    $snapshot = ProviderGeneration::fromApi(pixelforgeGeneration('succeeded', ['image_url' => $url]));

    expect($snapshot->imageUrl)->toBeNull();
})->with([
    'javascript' => ['javascript:alert(document.cookie)'],
    'data uri' => ['data:text/html;base64,PHNjcmlwdD4='],
    'plain http' => ['http://pixelforge.promptedit.dev/images/x.png'],
]);

it('refuses objects it cannot trust', function (array $overrides) {
    ProviderGeneration::fromApi(pixelforgeGeneration('succeeded', $overrides));
})->throws(Exception::class)->with([
    'no id' => [['id' => null]],
    'not a generation id' => [['id' => 'evt_123']],
    'unknown status' => [['status' => 'cancelled']],
    'succeeded without usage' => [['usage' => null]],
    'no rates' => [['rates' => null]],
]);
