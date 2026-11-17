<?php

namespace App\Actions\Webhooks;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Stores a verified webhook event exactly once and queues its processing.
 *
 * Delivery is "at least once", so the same event id can arrive many times (retries,
 * dashboard resends, the [duplicate] test keyword). The unique index on provider_event_id
 * makes the database the referee, which is safe even when two copies arrive at once.
 *
 * Ids come from the signed body, never from the Pixelforge-Event-Id header: headers are
 * not covered by the signature, so they could be altered.
 */
class RecordWebhookEvent
{
    /**
     * @param  array<mixed>  $event  decoded, signature-verified body
     *
     * @throws InvalidArgumentException when the body isn't a Pixelforge event
     */
    public function handle(array $event, int $deliveryAttempt): WebhookEvent
    {
        $id = $event['id'] ?? null;
        $type = $event['type'] ?? null;

        if (! is_string($id) || ! str_starts_with($id, 'evt_') || ! is_string($type) || $type === '') {
            throw new InvalidArgumentException('Webhook body is not a Pixelforge event.');
        }

        $generationId = $event['data']['object']['id'] ?? null;

        $record = WebhookEvent::createOrFirst(['provider_event_id' => $id], [
            'type' => $type,
            'provider_generation_id' => is_string($generationId) ? $generationId : null,
            'delivery_attempt' => max(1, $deliveryAttempt),
            'payload' => $event,
            'received_at' => now(),
        ]);

        if (! $record->wasRecentlyCreated) {
            Log::info('Duplicate Pixelforge webhook event ignored.', ['event_id' => $id, 'delivery_attempt' => $deliveryAttempt]);
        }

        // New event, or a redelivery of one whose processing never finished (e.g. a lost job).
        // Processing is idempotent, so dispatching twice is harmless; skipping it could lose an update.
        if ($record->processed_at === null) {
            ProcessWebhookEvent::dispatch($record);
        }

        return $record;
    }
}
