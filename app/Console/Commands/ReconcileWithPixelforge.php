<?php

namespace App\Console\Commands;

use App\Actions\Polling\RecoverPendingGenerations;
use App\Actions\Polling\RetryUnprocessedWebhookEvents;
use App\Actions\Polling\SyncStaleGenerations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The safety net behind webhooks, run every minute by the scheduler (routes/console.php).
 * Each step is a small Action; this command only runs them and reports.
 */
#[Signature('pixelforge:reconcile')]
#[Description('Repair generations and webhook events that webhooks alone did not resolve')]
class ReconcileWithPixelforge extends Command
{
    public function handle(
        SyncStaleGenerations $syncStale,
        RecoverPendingGenerations $recoverPending,
        RetryUnprocessedWebhookEvents $retryEvents,
    ): int {
        $synced = $syncStale->handle();
        $pending = $recoverPending->handle();
        $events = $retryEvents->handle();

        $this->info(sprintf(
            'Synced %d in-flight, re-queued %d pending, gave up on %d, re-queued %d webhook events.',
            $synced, $pending['resubmitted'], $pending['given_up'], $events,
        ));

        return self::SUCCESS;
    }
}
