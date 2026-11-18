<?php

namespace App\Actions\Polling;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Container\Attributes\Config;

/**
 * Webhook events that were stored but never marked processed (e.g. the job was lost when
 * a worker died). Re-queue them; processing is idempotent, so a second run is harmless.
 *
 * Parked events (failed_at set: they failed every round) are skipped, otherwise a few bad
 * events at the front of the "oldest first" queue would block every other retry.
 */
class RetryUnprocessedWebhookEvents
{
    public function __construct(
        #[Config('services.pixelforge.polling.retry_events_after_seconds')] private readonly int $retryAfterSeconds,
        #[Config('services.pixelforge.polling.batch_size')] private readonly int $batchSize,
    ) {}

    public function handle(): int
    {
        $events = WebhookEvent::query()
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where('received_at', '<=', now()->subSeconds($this->retryAfterSeconds))
            ->oldest('received_at')
            ->limit($this->batchSize)
            ->get();

        foreach ($events as $event) {
            ProcessWebhookEvent::dispatch($event);
        }

        return $events->count();
    }
}
