<?php

use App\Enums\GenerationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const GET_URL = 'https://pixelforge.test/v1/generations/*';

/** An in-flight generation linked to a Pixelforge id, last heard from $minutesAgo minutes ago. */
function inFlight(GenerationStatus $status = GenerationStatus::Processing, ?int $minutesAgo = 5, string $providerId = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'): Generation
{
    $generation = Generation::factory()->withStatus($status)->create();
    $generation->forceFill([
        'provider_generation_id' => $providerId,
        'last_synced_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
    ])->save();

    return $generation;
}

function reconcile(): void
{
    test()->artisan('pixelforge:reconcile')->assertSuccessful();
}

describe('in-flight generations (missed webhooks)', function () {
    it('reconciles a generation whose webhook never arrived, charging it once', function () {
        $generation = inFlight();
        Http::fake([GET_URL => Http::response(pixelforgeGeneration('succeeded'))]);

        reconcile();
        reconcile();   // a second run must not charge again

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_micro_usd)->toBe(381_888)
            ->and($generation->last_synced_at->diffInSeconds(now()))->toBeLessThan(5);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'
            && $r->hasHeader('Authorization', 'Bearer pf_test_key'));
    });

    it('records a failure found by polling at zero cost', function () {
        $generation = inFlight();
        Http::fake([GET_URL => Http::response(pixelforgeGeneration('failed'))]);

        reconcile();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charged_micro_usd)->toBe(0);
    });

    it('leaves generations alone that were updated recently', function () {
        inFlight(minutesAgo: 1);
        Http::fake();

        reconcile();

        Http::assertNothingSent();
    });

    it('ignores pending, finished and rejected generations', function () {
        Generation::factory()->withStatus(GenerationStatus::Succeeded)->create()->forceFill(['provider_generation_id' => 'gen_a'])->save();
        Generation::factory()->withStatus(GenerationStatus::Failed)->create()->forceFill(['provider_generation_id' => 'gen_b'])->save();
        Generation::factory()->withStatus(GenerationStatus::Rejected)->create();
        Http::fake();
        Queue::fake();

        reconcile();

        Http::assertNothingSent();
    });

    it('polls at most one batch per run, oldest first', function () {
        config(['services.pixelforge.polling.batch_size' => 2]);
        inFlight(minutesAgo: 3, providerId: 'gen_newer');
        inFlight(minutesAgo: null, providerId: 'gen_never');
        inFlight(minutesAgo: 10, providerId: 'gen_oldest');
        Http::fake([GET_URL => Http::response(pixelforgeGeneration('queued', ['status' => 'processing']))]);

        reconcile();

        $polled = Http::recorded()->map(fn (array $pair) => basename($pair[0]->url()))->all();
        expect($polled)->toBe(['gen_never', 'gen_oldest']);
    });

    it('stops the run when Pixelforge rate-limits us', function () {
        inFlight(providerId: 'gen_1');
        inFlight(providerId: 'gen_2');
        Http::fake([GET_URL => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429, ['Retry-After' => '30'])]);

        reconcile();

        Http::assertSentCount(1);
    });

    it('stops the run and changes nothing when Pixelforge is unreachable', function () {
        $generation = inFlight();
        Http::fake([GET_URL => Http::failedConnection()]);

        reconcile();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    });

    it('keeps going with the rest of the batch when one payload cannot be priced', function () {
        $broken = inFlight(providerId: 'gen_broken');
        $fine = inFlight(providerId: 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
        Http::fake([
            'https://pixelforge.test/v1/generations/gen_broken' => Http::response(pixelforgeGeneration('succeeded', ['id' => 'gen_broken', 'usage' => ['output_tokens' => 1]])),
            GET_URL => Http::response(pixelforgeGeneration('succeeded')),
        ]);

        reconcile();

        expect($broken->refresh()->status)->toBe(GenerationStatus::Processing)
            ->and($broken->charged_micro_usd)->toBeNull()
            ->and($broken->last_synced_at->diffInSeconds(now()))->toBeLessThan(5)
            ->and($fine->refresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('does not retry a 404 every minute', function () {
        $generation = inFlight();
        Http::fake([GET_URL => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation']], 404)]);

        reconcile();
        reconcile();

        Http::assertSentCount(1);
        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    });
});

describe('stuck pending generations', function () {
    beforeEach(fn () => Queue::fake());

    it('re-queues the submit job for a generation stuck in pending', function () {
        $stuck = Generation::factory()->create(['created_at' => now()->subMinutes(5)]);

        reconcile();

        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($stuck));
    });

    it('leaves a just-created pending generation to its own job', function () {
        Generation::factory()->create(['created_at' => now()->subSeconds(30)]);

        reconcile();

        Queue::assertNotPushed(SubmitGeneration::class);
    });

    it('gives up after an hour: rejected and never billed', function () {
        $old = Generation::factory()->create(['created_at' => now()->subMinutes(61)]);

        reconcile();

        expect($old->refresh()->status)->toBe(GenerationStatus::Rejected)
            ->and($old->error_code)->toBe('submission_timeout')
            ->and($old->charged_micro_usd)->toBe(0);
        Queue::assertNotPushed(SubmitGeneration::class);
    });

    it('never gives up on one whose outcome is unknown: keeps replaying the same key', function () {
        $unknown = Generation::factory()->create(['created_at' => now()->subHours(3)]);
        $unknown->forceFill(['submit_outcome_unknown' => true])->save();

        reconcile();

        expect($unknown->refresh()->status)->toBe(GenerationStatus::Pending);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($unknown));
    });
});

describe('unprocessed webhook events', function () {
    beforeEach(fn () => Queue::fake());

    function storedEvent(string $id, int $minutesAgo, bool $processed = false): WebhookEvent
    {
        $event = WebhookEvent::create([
            'provider_event_id' => $id, 'type' => 'generation.succeeded', 'delivery_attempt' => 1,
            'payload' => ['id' => $id], 'received_at' => now()->subMinutes($minutesAgo),
        ]);

        if ($processed) {
            $event->forceFill(['processed_at' => now()])->save();
        }

        return $event;
    }

    it('re-queues events that were stored but never processed', function () {
        storedEvent('evt_lost', minutesAgo: 10);
        storedEvent('evt_recent', minutesAgo: 1);
        storedEvent('evt_done', minutesAgo: 10, processed: true);

        reconcile();

        Queue::assertPushed(ProcessWebhookEvent::class, 1);
        Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $job) => $job->event->provider_event_id === 'evt_lost');
    });
});

it('is scheduled every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pixelforge:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
