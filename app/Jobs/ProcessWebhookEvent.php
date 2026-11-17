<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyProviderUpdate;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\ProviderGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies one stored webhook event. Runs on the queue so the webhook endpoint can answer
 * Pixelforge within its 10-second window regardless of how long this takes.
 *
 * The update and the "processed" mark are committed together: either both happen or
 * neither does (and the job, a redelivery or the poller will try again).
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> seconds between attempts */
    public array $backoff = [5, 30, 120, 600];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public WebhookEvent $event)
    {
        $this->afterCommit();
    }

    public function handle(ApplyProviderUpdate $applyUpdate): void
    {
        $event = $this->event->refresh();

        if ($event->processed_at !== null) {
            return;   // another copy of this job already handled it
        }

        DB::transaction(function () use ($event, $applyUpdate) {
            if (str_starts_with($event->type, 'generation.')) {
                $this->applyGenerationEvent($event, $applyUpdate);
            } elseif ($event->type === 'ping') {
                Log::info('Pixelforge test webhook (ping) received.', ['event_id' => $event->provider_event_id]);
            } else {
                Log::warning('Unknown Pixelforge webhook type stored but not applied.', ['type' => $event->type]);
            }

            // Guarded update: only the first copy to get here marks it.
            WebhookEvent::query()
                ->whereKey($event->id)
                ->whereNull('processed_at')
                ->update(['processed_at' => now()]);
        });
    }

    private function applyGenerationEvent(WebhookEvent $event, ApplyProviderUpdate $applyUpdate): void
    {
        $object = $event->payload['data']['object'] ?? null;

        // Go by the generation's status inside the payload, not by the event type:
        // ApplyProviderUpdate then decides whether that is a step forward.
        $generation = $applyUpdate->handle(ProviderGeneration::fromApi(is_array($object) ? $object : []));

        if ($generation === null) {
            Log::warning('Pixelforge webhook for a generation we do not have.', [
                'event_id' => $event->provider_event_id,
                'provider_generation_id' => $event->provider_generation_id,
            ]);
        }
    }
}
