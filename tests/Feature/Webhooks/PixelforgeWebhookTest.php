<?php

use App\Enums\GenerationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** A Pixelforge event wrapping a generation object, linked to $generation via metadata. */
function generationEvent(string $state, ?Generation $generation = null, ?string $eventId = null, array $overrides = []): array
{
    $object = pixelforgeGeneration($state, [
        'metadata' => $generation ? ['generation_id' => $generation->id] : [],
        ...$overrides,
    ]);

    return [
        'id' => $eventId ?? 'evt_'.Str::ulid(),
        'object' => 'event',
        'type' => 'generation.'.$object['status'],
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $object],
    ];
}

/**
 * POSTs the exact raw body with a real signature, like Pixelforge does.
 *
 * @param  array{body?: string, timestamp?: int, secret?: string, signature?: string, attempt?: int}  $options
 */
function deliver(array $event, array $options = []): TestResponse
{
    $body = $options['body'] ?? json_encode($event, JSON_UNESCAPED_SLASHES);
    $timestamp = $options['timestamp'] ?? time();
    $secret = $options['secret'] ?? 'whsec_test_secret';
    $signature = $options['signature'] ?? "t={$timestamp},v1=".WebhookSignature::sign($secret, $timestamp, $body);

    return test()->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? '',
        'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'] ?? '',
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) ($options['attempt'] ?? 1),
    ], content: $body);
}

describe('signature', function () {
    it('accepts a signed event without any CSRF token or session', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

        deliver(generationEvent('succeeded', $generation))->assertNoContent();

        expect(WebhookEvent::count())->toBe(1);
    });

    it('rejects an invalid signature before storing anything', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

        deliver(generationEvent('succeeded', $generation), ['secret' => 'whsec_wrong'])->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0)
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    });

    it('rejects a stale timestamp (replayed request)', function () {
        deliver(generationEvent('succeeded'), ['timestamp' => time() - 301])->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    });

    it('rejects a tampered body', function () {
        $event = generationEvent('succeeded');
        $signedBody = json_encode($event, JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        deliver($event, [
            'body' => str_replace('"succeeded"', '"failed"', $signedBody),
            'signature' => "t={$timestamp},v1=".WebhookSignature::sign('whsec_test_secret', $timestamp, $signedBody),
        ])->assertStatus(400);
    });

    it('uses the application clock for the replay window', function () {
        $signedAt = time();
        $this->travel(6)->minutes();

        deliver(generationEvent('succeeded'), ['timestamp' => $signedAt])->assertStatus(400);
    });

    it('rejects a request with no signature header', function () {
        deliver(generationEvent('succeeded'), ['signature' => ''])->assertStatus(400);
    });

    it('answers 500 and alerts when the secret is not configured, so Pixelforge retries', function () {
        Log::spy();
        config(['services.pixelforge.webhook_secret' => null]);

        deliver(generationEvent('succeeded'))->assertStatus(500);

        Log::shouldHaveReceived('critical')->once();
    });

    it('rejects a signed body that is not a Pixelforge event', function () {
        deliver([], ['body' => '{"hello":"world"}'])->assertStatus(400);
        deliver([], ['body' => 'not json'])->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    });
});

describe('applying events', function () {
    it('charges a succeeded generation exactly', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

        deliver(generationEvent('succeeded', $generation))->assertNoContent();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_micro_usd)->toBe(381_888)
            ->and($generation->provider_cost_nano_usd)->toBe(305_510_000)
            ->and(WebhookEvent::sole()->processed_at)->not->toBeNull();
    });

    it('records a failed generation at zero cost', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();

        deliver(generationEvent('failed', $generation));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charged_micro_usd)->toBe(0)
            ->and($generation->error_code)->toBe('internal_error');
    });

    it('moves a queued generation to processing', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Queued)->create();

        deliver(generationEvent('queued', $generation, overrides: ['status' => 'processing', 'started_at' => '2026-11-04T14:03:13Z']));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
            ->and($generation->started_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z');
    });

    it('matches a webhook that arrives before our create call returned (via metadata)', function () {
        $generation = Generation::factory()->create();   // still pending, no provider id yet

        deliver(generationEvent('succeeded', $generation));

        $generation->refresh();
        expect($generation->provider_generation_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_micro_usd)->toBe(381_888);
    });

    it('acknowledges and stores a webhook for a generation we do not know', function () {
        deliver(generationEvent('succeeded'))->assertNoContent();

        expect(WebhookEvent::sole()->processed_at)->not->toBeNull();
    });

    it('acknowledges a ping from the dashboard', function () {
        deliver(['id' => 'evt_ping_1', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []])
            ->assertNoContent();

        expect(WebhookEvent::sole()->type)->toBe('ping')
            ->and(WebhookEvent::sole()->processed_at)->not->toBeNull();
    });
});

describe('duplicates and ordering', function () {
    it('stores and applies a duplicate event only once, still answering 2xx', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();
        $event = generationEvent('succeeded', $generation, 'evt_dup');

        deliver($event, ['attempt' => 1])->assertNoContent();
        deliver($event, ['attempt' => 2])->assertNoContent();

        expect(WebhookEvent::count())->toBe(1)
            ->and(WebhookEvent::sole()->delivery_attempt)->toBe(1)
            ->and($generation->refresh()->charged_micro_usd)->toBe(381_888);
    });

    it('does not reprocess an already processed duplicate', function () {
        Queue::fake();
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();
        $event = generationEvent('succeeded', $generation, 'evt_done');
        WebhookEvent::create([
            'provider_event_id' => 'evt_done', 'type' => 'generation.succeeded', 'delivery_attempt' => 1,
            'payload' => $event, 'received_at' => now(),
        ])->forceFill(['processed_at' => now()])->save();

        deliver($event, ['attempt' => 2])->assertNoContent();

        Queue::assertNothingPushed();
    });

    it('re-dispatches a duplicate whose first processing never finished', function () {
        Queue::fake();
        $event = generationEvent('succeeded', eventId: 'evt_lost');
        WebhookEvent::create([
            'provider_event_id' => 'evt_lost', 'type' => 'generation.succeeded', 'delivery_attempt' => 1,
            'payload' => $event, 'received_at' => now(),
        ]);

        deliver($event, ['attempt' => 2])->assertNoContent();

        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    });

    it('keeps succeeded when a late processing event arrives (out of order), charged once', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Queued)->create();

        deliver(generationEvent('succeeded', $generation));
        deliver(generationEvent('queued', $generation, overrides: ['status' => 'processing']));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_micro_usd)->toBe(381_888)
            ->and(WebhookEvent::count())->toBe(2)
            ->and(WebhookEvent::whereNull('processed_at')->count())->toBe(0);
    });

    it('trusts the signed body id, not the unsigned Pixelforge-Event-Id header', function () {
        $event = generationEvent('succeeded', eventId: 'evt_body');
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        test()->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1=".WebhookSignature::sign('whsec_test_secret', $timestamp, $body),
            'HTTP_PIXELFORGE_EVENT_ID' => 'evt_header_lie',
        ], content: $body)->assertNoContent();

        expect(WebhookEvent::sole()->provider_event_id)->toBe('evt_body');
    });
});

describe('processing job', function () {
    it('leaves the event unprocessed if applying fails, so it can be retried', function () {
        $generation = Generation::factory()->withStatus(GenerationStatus::Processing)->create();
        $event = WebhookEvent::create([
            'provider_event_id' => 'evt_bad', 'type' => 'generation.succeeded', 'delivery_attempt' => 1,
            // Succeeded without usage: cannot be priced, so it must not be marked processed.
            'payload' => generationEvent('succeeded', $generation, 'evt_bad', ['usage' => null]),
            'received_at' => now(),
        ]);

        expect(fn () => app()->call([new ProcessWebhookEvent($event), 'handle']))->toThrow(InvalidArgumentException::class);

        expect($event->refresh()->processed_at)->toBeNull()
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    });
});
