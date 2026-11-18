<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const CREATE_URL = 'https://pixelforge.test/v1/generations';

/** Runs the job once, with fake queue interactions so release() can be asserted. */
function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function pixelforgeError(int $status, string $code, ?int $retryAfter = null): mixed
{
    return Http::response(
        ['error' => ['type' => 'test_error', 'code' => $code, 'message' => "Pixelforge says {$code}."]],
        $status,
        $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
    );
}

function accepted(Generation $generation, string $state = 'queued'): mixed
{
    return Http::response(pixelforgeGeneration($state, ['metadata' => ['generation_id' => $generation->id]]), 202);
}

describe('accepted (202)', function () {
    it('sends the right request and stores the provider id', function () {
        $generation = Generation::factory()->create([
            'prompt' => 'A lighthouse',
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::High,
        ]);
        Http::fake([CREATE_URL => accepted($generation)]);

        runSubmit($generation)->assertNotReleased();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === CREATE_URL
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $request->data() === [
                'prompt' => 'A lighthouse',
                'size' => '1536x1024',
                'quality' => 'high',
                'metadata' => ['generation_id' => $generation->id],
                // No empty reference_images: Pixelforge rejects anything it doesn't expect.
            ]);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->provider_generation_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
            ->and($generation->submit_attempts)->toBe(1)
            ->and($generation->submitted_at)->not->toBeNull();
    });

    it('sends reference images when there are some', function () {
        $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);
        Http::fake([CREATE_URL => accepted($generation)]);

        runSubmit($generation);

        Http::assertSent(fn (Request $request) => $request['reference_images'] === ['https://example.com/a.png']);
    });

    it('applies a replayed response that has already succeeded, charging it', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => accepted($generation, 'succeeded')]);

        runSubmit($generation);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_micro_usd)->toBe(381_888);
    });

    it('does nothing if the generation is no longer pending (a webhook got there first)', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();
        Http::fake();

        runSubmit($generation)->assertNotReleased();

        Http::assertNothingSent();
    });
});

describe('rate limits and overloads (nothing was created)', function () {
    it('honours Retry-After on 429 rate_limit_exceeded', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => pixelforgeError(429, 'rate_limit_exceeded', retryAfter: 17)]);

        runSubmit($generation)->assertReleased(delay: 17);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->error_code)->toBe('rate_limit_exceeded')
            ->and($generation->error_message)->toBe('Pixelforge is busy, retrying…');
    });

    it('backs off on 503 overloaded, at least as long as Retry-After', function () {
        $generation = Generation::factory()->create();
        $generation->forceFill(['submit_attempts' => 2])->save();
        Http::fake([CREATE_URL => pixelforgeError(503, 'overloaded', retryAfter: 2)]);

        // 3rd attempt: backoff 2^3 = 8s beats Retry-After 2s.
        runSubmit($generation)->assertReleased(delay: 8);
    });

    it('rejects after the attempt cap, never billed', function () {
        $generation = Generation::factory()->create();
        $generation->forceFill(['submit_attempts' => SubmitGeneration::MAX_ATTEMPTS - 1])->save();
        Http::fake([CREATE_URL => pixelforgeError(503, 'overloaded', retryAfter: 2)]);

        runSubmit($generation)->assertNotReleased();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->charged_micro_usd)->toBe(0)
            ->and($generation->error_message)->toContain('You were not charged');
    });

    it('treats a 429 without a known code as temporary, never permanent', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => Http::response('Too Many Requests', 429, ['Retry-After' => '9'])]);

        runSubmit($generation)->assertReleased(delay: 9);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
            ->and($generation->error_code)->toBe('http_429');
    });

    it('reuses the same Idempotency-Key on every retry', function () {
        $generation = Generation::factory()->create();
        Http::fakeSequence(CREATE_URL)
            ->pushResponse(pixelforgeError(503, 'overloaded', retryAfter: 2))
            ->pushResponse(accepted($generation));

        runSubmit($generation)->assertReleased();
        runSubmit($generation)->assertNotReleased();

        $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])->unique();

        expect(Http::recorded())->toHaveCount(2)
            ->and($keys->all())->toBe([$generation->idempotency_key])
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued)
            ->and($generation->submit_attempts)->toBe(2)
            ->and($generation->error_message)->toBeNull();
    });
});

describe('concurrency limit (wait for a free slot)', function () {
    it('waits locally, without calling Pixelforge, when our own generations fill every slot', function () {
        Generation::factory()->count(2)->withStatus(GenerationStatus::Processing)->create();
        Generation::factory()->withStatus(GenerationStatus::Queued)->create();
        $generation = Generation::factory()->create();
        Http::fake();

        runSubmit($generation)->assertReleased(delay: 5);

        Http::assertNothingSent();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
            ->and($generation->submit_attempts)->toBe(0)
            ->and($generation->error_message)->toBe('Waiting for a free generation slot…');
    });

    it('after an unknown outcome, asks Pixelforge instead of waiting (and never rejecting) locally', function () {
        Generation::factory()->count(3)->withStatus(GenerationStatus::Processing)->create();
        $generation = Generation::factory()->create();
        $generation->forceFill([
            'submit_outcome_unknown' => true,
            'submitted_at' => now()->subMinutes(SubmitGeneration::SLOT_WAIT_MINUTES + 1),
        ])->save();
        // The earlier attempt did create it: the replay needs no slot.
        Http::fake([CREATE_URL => accepted($generation)]);

        runSubmit($generation)->assertNotReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('submits as soon as a slot is free', function () {
        Generation::factory()->count(2)->withStatus(GenerationStatus::Processing)->create();
        Generation::factory()->withStatus(GenerationStatus::Succeeded)->create();
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => accepted($generation)]);

        runSubmit($generation)->assertNotReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('does not let stale in-flight rows (lost webhooks) block submissions forever', function () {
        Generation::factory()->count(3)->withStatus(GenerationStatus::Processing)->create(['created_at' => now()->subMinutes(31)]);
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => accepted($generation)]);

        runSubmit($generation)->assertNotReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('still gives up after the slot-wait budget when waiting locally', function () {
        Generation::factory()->count(3)->withStatus(GenerationStatus::Processing)->create();
        $generation = Generation::factory()->create();
        $generation->forceFill(['submitted_at' => now()->subMinutes(SubmitGeneration::SLOT_WAIT_MINUTES + 1)])->save();
        Http::fake();

        runSubmit($generation)->assertNotReleased();

        Http::assertNothingSent();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected);
    });

    it('waits Retry-After without using up an attempt', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => pixelforgeError(429, 'concurrency_limit_exceeded', retryAfter: 5)]);

        runSubmit($generation)->assertReleased(delay: 5);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->submit_attempts)->toBe(0)
            ->and($generation->error_message)->toBe('Waiting for a free generation slot…');
    });

    it('gives up after waiting too long, never billed', function () {
        $generation = Generation::factory()->create();
        $generation->forceFill(['submitted_at' => now()->subMinutes(SubmitGeneration::SLOT_WAIT_MINUTES + 1)])->save();
        Http::fake([CREATE_URL => pixelforgeError(429, 'concurrency_limit_exceeded', retryAfter: 5)]);

        runSubmit($generation)->assertNotReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->error_code)->toBe('concurrency_limit_exceeded');
    });
});

describe('permanent refusals (reject now, no retry)', function () {
    it('rejects a blocked prompt with a friendly reason', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => pixelforgeError(400, 'content_policy_violation')]);

        runSubmit($generation)->assertNotReleased();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->error_code)->toBe('content_policy_violation')
            ->and($generation->error_message)->toContain('blocked by the content policy')
            ->and($generation->charged_micro_usd)->toBe(0);
    });

    it('rejects and alerts operators on our own errors', function (int $status, string $code) {
        Log::spy();
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => pixelforgeError($status, $code)]);

        runSubmit($generation)->assertNotReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected);
        Log::shouldHaveReceived('critical')->once();
    })->with([
        'bad API key' => [401, 'invalid_api_key'],
        'validation drift' => [422, 'validation_failed'],
        'idempotency conflict' => [409, 'idempotency_key_reused'],
    ]);

    it('rejects when the daily quota is used up', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => pixelforgeError(429, 'daily_limit_exceeded')]);

        runSubmit($generation)->assertNotReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->error_message)->toContain('midnight UTC');
    });
});

describe('unknown outcome (Pixelforge may have created it)', function () {
    it('retries a network failure without rejecting', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => Http::failedConnection()]);

        runSubmit($generation)->assertReleased(delay: 2);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
            ->and($generation->error_code)->toBe('network_error');
    });

    it('retries an unexpected 5xx without rejecting', function () {
        $generation = Generation::factory()->create();
        Http::fake([CREATE_URL => Http::response('Bad gateway', 502)]);

        runSubmit($generation)->assertReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
    });

    it('never rejects on later 503s once an earlier attempt had an unknown outcome', function () {
        $generation = Generation::factory()->create();
        Http::fakeSequence(CREATE_URL)
            ->pushFailedConnection()   // attempt 1: maybe created
            ->pushResponse(pixelforgeError(503, 'overloaded', retryAfter: 2))
            ->pushResponse(pixelforgeError(503, 'overloaded', retryAfter: 2))
            ->pushResponse(pixelforgeError(503, 'overloaded', retryAfter: 2))
            ->pushResponse(pixelforgeError(503, 'overloaded', retryAfter: 2));

        foreach (range(1, SubmitGeneration::MAX_ATTEMPTS) as $attempt) {
            runSubmit($generation);
        }

        // A 503 only proves THAT request created nothing; attempt 1 may still exist at Pixelforge.
        $generation->refresh();
        expect($generation->submit_attempts)->toBe(SubmitGeneration::MAX_ATTEMPTS)
            ->and($generation->submit_outcome_unknown)->toBeTrue()
            ->and($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->charged_micro_usd)->toBeNull();
    });

    it('stays pending (not rejected) after the cap, for the poller to resolve', function () {
        $generation = Generation::factory()->create();
        $generation->forceFill(['submit_attempts' => SubmitGeneration::MAX_ATTEMPTS - 1])->save();
        Http::fake([CREATE_URL => Http::failedConnection()]);

        runSubmit($generation)->assertNotReleased();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
            ->and($generation->charged_micro_usd)->toBeNull();
    });
});
