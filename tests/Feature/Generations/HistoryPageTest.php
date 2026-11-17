<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
});

/** A generation for $user in the given state, with money and image fields like real data. */
function generationFor(User $user, GenerationStatus $status, array $attributes = []): Generation
{
    $generation = Generation::factory()->for($user)->withStatus($status)->create();
    $generation->forceFill($attributes)->save();

    return $generation;
}

function historyCard(User $user): array
{
    $props = test()->actingAs($user)->get(route('generations.index'))->viewData('page')['props'];

    return $props['generations']['data'][0];
}

describe('status and error messages', function () {
    it('explains every state in plain English', function (GenerationStatus $status, array $attributes, string $label, ?string $message) {
        $user = User::factory()->create();
        generationFor($user, $status, $attributes);

        $card = historyCard($user);

        expect($card['status_label'])->toBe($label)
            ->and($card['status_message'])->toBe($message);
    })->with([
        'pending' => [GenerationStatus::Pending, [], 'Submitting', 'Sending to Pixelforge…'],
        'pending, provider busy' => [GenerationStatus::Pending, ['error_code' => 'overloaded', 'error_message' => 'Pixelforge is busy, retrying…'], 'Submitting', 'Pixelforge is busy, retrying…'],
        'queued' => [GenerationStatus::Queued, [], 'Queued', "Waiting in Pixelforge's queue…"],
        'processing' => [GenerationStatus::Processing, [], 'Generating', 'Generating your image…'],
        'succeeded' => [GenerationStatus::Succeeded, ['charged_micro_usd' => 18_872], 'Succeeded', null],
        'failed, internal error' => [GenerationStatus::Failed, ['error_code' => 'internal_error', 'charged_micro_usd' => 0], 'Failed', 'Failed during generation because of a problem at Pixelforge. You were not charged. Trying again usually works.'],
        'failed, model could not draw it' => [GenerationStatus::Failed, ['error_code' => 'generation_failed', 'charged_micro_usd' => 0], 'Failed', "Pixelforge couldn't produce an image for this prompt. You were not charged."],
        'rejected, blocked prompt' => [GenerationStatus::Rejected, ['error_code' => 'content_policy_violation', 'error_message' => 'Your prompt was blocked by the content policy. Try rewording it. You were not charged.'], 'Not accepted', 'Your prompt was blocked by the content policy. Try rewording it. You were not charged.'],
    ]);

    it('flags a pending generation that is retrying, so the UI can show a warning', function () {
        $user = User::factory()->create();
        generationFor($user, GenerationStatus::Pending, ['error_code' => 'rate_limit_exceeded', 'error_message' => 'Pixelforge is busy, retrying…']);

        expect(historyCard($user)['is_retrying'])->toBeTrue();
    });
});

describe('money', function () {
    it('shows the estimate next to the actual charge', function () {
        $user = User::factory()->create();
        generationFor($user, GenerationStatus::Succeeded, [
            'estimated_min_micro_usd' => 14_031,
            'estimated_max_micro_usd' => 21_869,
            'charged_micro_usd' => 18_872,
            'provider_cost_nano_usd' => 15_097_500,
        ]);

        $card = historyCard($user);

        expect($card['estimate'])->toBe(['min' => '$0.014031', 'max' => '$0.021869'])
            ->and($card['charged'])->toBe('$0.018872')
            ->and($card['charge_note'])->toBe('Actual cost incl. 25% fee')
            // Our cost from Pixelforge is internal and never sent to the browser.
            ->and($card)->not->toHaveKey('provider_cost_nano_usd');
    });

    it('says failed and rejected generations were not charged', function (GenerationStatus $status) {
        $user = User::factory()->create();
        generationFor($user, $status, ['charged_micro_usd' => 0]);

        expect(historyCard($user)['charge_note'])->toBe('Not charged');
    })->with([GenerationStatus::Failed, GenerationStatus::Rejected]);

    it('says in-flight generations are charged when finished', function () {
        $user = User::factory()->create();
        generationFor($user, GenerationStatus::Processing);

        expect(historyCard($user)['charged'])->toBeNull()
            ->and(historyCard($user)['charge_note'])->toBe('Charged when finished');
    });

    it('totals only what this user was charged for succeeded generations', function () {
        $user = User::factory()->create();
        generationFor($user, GenerationStatus::Succeeded, ['charged_micro_usd' => 18_872]);
        generationFor($user, GenerationStatus::Succeeded, ['charged_micro_usd' => 15_600]);
        generationFor($user, GenerationStatus::Failed, ['charged_micro_usd' => 0]);
        generationFor(User::factory()->create(), GenerationStatus::Succeeded, ['charged_micro_usd' => 999_999]);

        $this->actingAs($user)->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_charged', '$0.034472')
                ->where('summary.succeeded', 2)
                ->where('generations.meta.total', 3)
            );
    });
});

describe('images', function () {
    it('shows the image while its link is valid', function () {
        $user = User::factory()->create();
        generationFor($user, GenerationStatus::Succeeded, [
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc',
            'image_url_expires_at' => now()->addHours(23),
        ]);

        $card = historyCard($user);

        expect($card['image_url'])->toBe('https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc')
            ->and($card['image_expired'])->toBeFalse();
    });

    it('hides the link once it has expired', function () {
        $user = User::factory()->create();
        generationFor($user, GenerationStatus::Succeeded, [
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc',
            'image_url_expires_at' => now()->subMinute(),
        ]);

        $card = historyCard($user);

        expect($card['image_url'])->toBeNull()
            ->and($card['image_expired'])->toBeTrue();
    });
});

describe('live updates', function () {
    it('answers the polling partial reload with only the requested props', function () {
        $user = User::factory()->create();
        generationFor($user, GenerationStatus::Processing);

        $this->actingAs($user)->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['generations'], fn (Assert $reload) => $reload
                    ->has('generations.data', 1)
                    ->missing('summary')
                )
            );
    });
});

describe('try again', function () {
    it('pre-fills the form from one of my generations', function () {
        $user = User::factory()->create();
        $failed = generationFor($user, GenerationStatus::Failed);
        $failed->forceFill([
            'prompt' => 'A red fox in snow',
            'size' => ImageSize::Portrait,
            'quality' => ImageQuality::High,
            'reference_images' => ['https://example.com/a.png'],
        ])->save();

        $this->actingAs($user)->get(route('generations.create', ['from' => $failed->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill', [
                    'prompt' => 'A red fox in snow',
                    'size' => '1024x1536',
                    'quality' => 'high',
                    'reference_images' => ['https://example.com/a.png'],
                ])
                // The estimate matches the pre-filled options, not the defaults.
                ->where('initialEstimate.max', fn (string $max) => $max !== '$0.058125')
            );
    });

    it("never pre-fills from someone else's generation", function () {
        $theirs = generationFor(User::factory()->create(), GenerationStatus::Failed, ['prompt' => 'secret prompt']);

        $this->actingAs(User::factory()->create())->get(route('generations.create', ['from' => $theirs->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('prefill.prompt', ''));
    });
});
