<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('stores a generation with a ULID and casts enums, JSON and money integers', function () {
    $generation = Generation::factory()->create([
        'size' => ImageSize::Landscape,
        'quality' => ImageQuality::High,
        'reference_images' => ['https://example.com/a.png'],
    ]);

    // Money fields are written by ApplyProviderUpdate via forceFill, never mass assignment.
    $generation->forceFill([
        'usage' => ['input_text_tokens' => 12, 'output_tokens' => 6450],
        'rates' => ['output_tokens' => '40.00'],
        // Larger than a 32-bit int to prove BIGINT round-trips exactly.
        'provider_cost_nano_usd' => 9_876_543_210_123,
        'charged_micro_usd' => 12_345_678_901,
    ])->save();

    $fresh = $generation->fresh();

    expect(Str::isUlid($fresh->id))->toBeTrue()
        ->and($fresh->status)->toBe(GenerationStatus::Pending)
        ->and($fresh->size)->toBe(ImageSize::Landscape)
        ->and($fresh->quality)->toBe(ImageQuality::High)
        ->and($fresh->reference_images)->toBe(['https://example.com/a.png'])
        // MySQL JSON columns normalise key order, so compare by key, not position.
        ->and($fresh->usage)->toEqual(['input_text_tokens' => 12, 'output_tokens' => 6450])
        ->and($fresh->rates)->toBe(['output_tokens' => '40.00'])   // rates stay strings, never floats
        ->and($fresh->provider_cost_nano_usd)->toBe(9_876_543_210_123)
        ->and($fresh->charged_micro_usd)->toBe(12_345_678_901)
        ->and($fresh->user)->toBeInstanceOf(User::class);
});

it('does not allow money or provider fields to be mass assigned', function () {
    $generation = new Generation([
        'status' => GenerationStatus::Succeeded,
        'charged_micro_usd' => 1,
        'provider_cost_nano_usd' => 1,
        'provider_generation_id' => 'gen_x',
        'prompt' => 'kept',
    ]);

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->charged_micro_usd)->toBeNull()
        ->and($generation->provider_cost_nano_usd)->toBeNull()
        ->and($generation->provider_generation_id)->toBeNull()
        ->and($generation->prompt)->toBe('kept');
});

it('enforces a unique idempotency key', function () {
    $key = (string) Str::uuid();
    Generation::factory()->create(['idempotency_key' => $key]);

    Generation::factory()->create(['idempotency_key' => $key]);
})->throws(UniqueConstraintViolationException::class);

it('enforces a unique provider generation id', function () {
    Generation::factory()->create()->forceFill(['provider_generation_id' => 'gen_1'])->save();

    Generation::factory()->create()->forceFill(['provider_generation_id' => 'gen_1'])->save();
})->throws(UniqueConstraintViolationException::class);

it('keeps money records when the user deletes their account', function () {
    $generation = Generation::factory()->create();

    $generation->user->delete();

    expect($generation->fresh())->not->toBeNull()
        ->and($generation->fresh()->user_id)->toBeNull();
});

it('knows when the image link has expired', function () {
    $generation = Generation::factory()->make(['image_url_expires_at' => null]);
    $generation->image_url_expires_at = Carbon::parse('2026-11-05T14:03:29Z');

    expect($generation->imageUrlHasExpired(Carbon::parse('2026-11-05T14:03:28Z')))->toBeFalse()
        ->and($generation->imageUrlHasExpired(Carbon::parse('2026-11-05T14:03:29Z')))->toBeTrue();

    $generation->image_url_expires_at = null;
    expect($generation->imageUrlHasExpired())->toBeFalse();
});

it('stores each webhook event only once', function () {
    $attributes = [
        'provider_event_id' => 'evt_1',
        'type' => 'generation.succeeded',
        'provider_generation_id' => 'gen_1',
        'delivery_attempt' => 1,
        'payload' => ['id' => 'evt_1'],
        'received_at' => now(),
    ];

    WebhookEvent::create($attributes);
    WebhookEvent::create([...$attributes, 'delivery_attempt' => 2]);
})->throws(UniqueConstraintViolationException::class);

it('runs the database session in UTC so per-day money reports match Pixelforge', function () {
    expect(DB::selectOne('select @@session.time_zone as tz')->tz)->toBe('+00:00');
});
