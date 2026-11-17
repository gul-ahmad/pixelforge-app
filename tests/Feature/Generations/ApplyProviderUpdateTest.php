<?php

use App\Actions\Generations\ApplyProviderUpdate;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\ProviderGeneration;

/** Snapshot of the docs example generation, linked to $generation through metadata. */
function snapshotFor(Generation $generation, string $state, array $overrides = []): ProviderGeneration
{
    return ProviderGeneration::fromApi(pixelforgeGeneration($state, [
        'metadata' => ['generation_id' => $generation->id],
        ...$overrides,
    ]));
}

function apply(ProviderGeneration $snapshot): ?Generation
{
    return app(ApplyProviderUpdate::class)->handle($snapshot);
}

it('links a pending generation to Pixelforge when it is accepted', function () {
    $generation = Generation::factory()->create();
    $generation->forceFill(['error_code' => 'overloaded', 'error_message' => 'Pixelforge is busy, retrying…'])->save();

    apply(snapshotFor($generation, 'queued'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_generation_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation->rates)->toEqual(pixelforgeGeneration()['rates'])
        ->and($generation->last_synced_at)->not->toBeNull()
        ->and($generation->error_message)->toBeNull()
        ->and($generation->charged_micro_usd)->toBeNull();
});

it('charges the exact cost plus 25% when a generation succeeds', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

    apply(snapshotFor($generation, 'succeeded'));

    // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 micro-$ cost → ×1.25 = 381,887.5 → 381,888
    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nano_usd)->toBe(305_510_000)
        ->and($generation->charged_micro_usd)->toBe(381_888)
        ->and($generation->usage)->toEqual(pixelforgeGeneration('succeeded')['usage'])
        ->and($generation->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($generation->image_url_expires_at->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
        ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
});

it('charges only once when the succeeded update arrives twice', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

    apply(snapshotFor($generation, 'succeeded'));
    // A second copy with different numbers must change nothing.
    apply(snapshotFor($generation, 'succeeded', ['usage' => [
        'input_text_tokens' => 999, 'input_image_tokens' => 0, 'output_tokens' => 99_999, 'reasoning_tokens' => 0, 'total_tokens' => 0,
    ]]));

    expect($generation->refresh()->charged_micro_usd)->toBe(381_888);
});

it('ignores a late processing update after success (out of order)', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

    apply(snapshotFor($generation, 'succeeded'));
    apply(snapshotFor($generation, 'queued', ['status' => 'processing']));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_micro_usd)->toBe(381_888);
});

it('ignores a replayed queued response once processing', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Pending)->create();

    apply(snapshotFor($generation, 'queued', ['status' => 'processing', 'started_at' => '2026-11-04T14:03:13Z']));
    apply(snapshotFor($generation, 'queued'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('records a failed generation at zero cost with the reason', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

    apply(snapshotFor($generation, 'failed'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_micro_usd)->toBe(0)
        ->and($generation->provider_cost_nano_usd)->toBe(0)
        ->and($generation->error_code)->toBe('internal_error')
        ->and($generation->error_message)->toContain('You were not charged')
        ->and($generation->completed_at)->not->toBeNull();
});

it('never turns a failed generation into a paid one', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

    apply(snapshotFor($generation, 'failed'));
    apply(snapshotFor($generation, 'succeeded'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_micro_usd)->toBe(0);
});

it('matches an update that arrives before the create response, using our id in metadata', function () {
    $generation = Generation::factory()->create();   // pending, provider id still unknown

    apply(snapshotFor($generation, 'succeeded'));

    $generation->refresh();
    expect($generation->provider_generation_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_micro_usd)->toBe(381_888);
});

it('matches by provider id when metadata is missing', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Queued)->create();
    $generation->forceFill(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'])->save();

    apply(ProviderGeneration::fromApi(pixelforgeGeneration('succeeded')));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('returns null for a generation we do not know', function () {
    expect(apply(ProviderGeneration::fromApi(pixelforgeGeneration('succeeded'))))->toBeNull();
});

it('refuses to merge an update into a row already linked to another Pixelforge generation', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Queued)->create();
    $generation->forceFill(['provider_generation_id' => 'gen_OTHER'])->save();

    expect(apply(snapshotFor($generation, 'succeeded')))->toBeNull()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->charged_micro_usd)->toBeNull();
});

it('lets Pixelforge override a local rejection, because the generation really exists', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Rejected)->create();

    apply(snapshotFor($generation, 'succeeded'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_micro_usd)->toBe(381_888);
});

it('changes nothing if the usage cannot be priced (transaction rolls back)', function () {
    $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

    expect(fn () => apply(snapshotFor($generation, 'succeeded', ['usage' => ['output_tokens' => 10]])))
        ->toThrow(InvalidArgumentException::class);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->charged_micro_usd)->toBeNull()
        ->and($generation->provider_generation_id)->toBeNull();
});
