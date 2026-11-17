<?php

namespace App\Actions\Polling;

use App\Actions\Generations\RejectGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Container\Attributes\Config;

/**
 * Generations stuck in "pending" (never confirmed by Pixelforge): the worker crashed, the
 * submit job ran out of retries on network errors, or Pixelforge was unreachable.
 *
 * - Re-queue the submit job. Safe: it reuses the same Idempotency-Key, so if Pixelforge did
 *   create it, the retry just replays it. ShouldBeUnique stops a second copy while one runs.
 * - After the give-up age, stop and mark it rejected (not billed). If Pixelforge later reports
 *   it after all, ApplyProviderUpdate lets the provider's state win (rejected → succeeded).
 */
class RecoverPendingGenerations
{
    public function __construct(
        private readonly RejectGeneration $reject,
        #[Config('services.pixelforge.polling.resubmit_pending_after_seconds')] private readonly int $resubmitAfterSeconds,
        #[Config('services.pixelforge.polling.give_up_pending_after_minutes')] private readonly int $giveUpAfterMinutes,
        #[Config('services.pixelforge.polling.batch_size')] private readonly int $batchSize,
    ) {}

    /**
     * @return array{resubmitted: int, given_up: int}
     */
    public function handle(): array
    {
        $giveUpBefore = now()->subMinutes($this->giveUpAfterMinutes);

        $tooOld = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<=', $giveUpBefore)
            ->limit($this->batchSize)
            ->get();

        foreach ($tooOld as $generation) {
            $this->reject->handle($generation, 'submission_timeout',
                "We couldn't get Pixelforge to accept this generation in time. You were not charged. Please try again.");
        }

        $stuck = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<=', now()->subSeconds($this->resubmitAfterSeconds))
            ->where('created_at', '>', $giveUpBefore)
            ->oldest()
            ->limit($this->batchSize)
            ->get();

        foreach ($stuck as $generation) {
            SubmitGeneration::dispatch($generation);
        }

        return ['resubmitted' => $stuck->count(), 'given_up' => $tooOld->count()];
    }
}
