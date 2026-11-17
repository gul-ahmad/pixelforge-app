<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\ProviderGeneration;
use App\Support\Pricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE single place where Pixelforge's view of a generation is applied to ours.
 * Called with the 202 create response, every webhook, and every poll.
 *
 * Guarantees:
 *  - Status only moves forward (GenerationStatus::canTransitionTo); late or duplicate
 *    snapshots are recorded as a sync but change nothing.
 *  - The charge is calculated and written exactly once: on the first move to succeeded,
 *    inside a transaction holding a row lock, so two concurrent updates (say a webhook
 *    and a poll) cannot both charge.
 *  - Failed generations cost 0.
 */
class ApplyProviderUpdate
{
    /**
     * @return Generation|null null when the snapshot matches none of our generations
     */
    public function handle(ProviderGeneration $snapshot): ?Generation
    {
        return DB::transaction(function () use ($snapshot) {
            $generation = $this->findAndLock($snapshot);

            if ($generation === null) {
                return null;
            }

            $generation->provider_generation_id ??= $snapshot->id;
            $generation->last_synced_at = now();

            if ($generation->status->canTransitionTo($snapshot->status)) {
                $this->transition($generation, $snapshot);
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * Match by Pixelforge's id first; fall back to our own id in metadata, which covers a
     * webhook that arrives before the create call has returned and stored the provider id.
     */
    private function findAndLock(ProviderGeneration $snapshot): ?Generation
    {
        $generation = Generation::query()
            ->where('provider_generation_id', $snapshot->id)
            ->lockForUpdate()
            ->first();

        if ($generation === null && $snapshot->localGenerationId !== null) {
            $generation = Generation::query()
                ->whereKey($snapshot->localGenerationId)
                ->lockForUpdate()
                ->first();
        }

        if ($generation === null) {
            Log::warning('Pixelforge update for an unknown generation.', ['provider_generation_id' => $snapshot->id]);

            return null;
        }

        // Our metadata points at a row that is already linked to another Pixelforge generation.
        // Never merge the two: that could charge one user for someone else's image.
        if ($generation->provider_generation_id !== null && $generation->provider_generation_id !== $snapshot->id) {
            Log::error('Pixelforge update conflicts with the stored provider id.', [
                'generation_id' => $generation->id,
                'stored' => $generation->provider_generation_id,
                'received' => $snapshot->id,
            ]);

            return null;
        }

        return $generation;
    }

    private function transition(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->status = $snapshot->status;
        $generation->rates = $snapshot->rates;
        $generation->started_at ??= $snapshot->startedAt;

        // Any "retrying…" note from the submit phase no longer applies.
        $generation->error_code = null;
        $generation->error_message = null;

        match ($snapshot->status) {
            GenerationStatus::Succeeded => $this->markSucceeded($generation, $snapshot),
            GenerationStatus::Failed => $this->markFailed($generation, $snapshot),
            default => null,
        };
    }

    private function markSucceeded(Generation $generation, ProviderGeneration $snapshot): void
    {
        $costNano = Pricing::providerCostNanoUsd((array) $snapshot->usage, $snapshot->rates);

        /** @var array<string, int> $usage validated as integers by Pricing above */
        $usage = (array) $snapshot->usage;

        $generation->usage = $usage;
        $generation->provider_cost_nano_usd = $costNano;
        $generation->charged_micro_usd = Pricing::customerChargeMicroUsd($costNano);
        $generation->image_url = $snapshot->imageUrl;
        $generation->image_url_expires_at = $snapshot->imageUrlExpiresAt;
        $generation->completed_at = $snapshot->completedAt ?? now();
    }

    /** Failed generations are never billed by Pixelforge, so they cost the user nothing. */
    private function markFailed(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->provider_cost_nano_usd = 0;
        $generation->charged_micro_usd = 0;
        $generation->error_code = $snapshot->errorCode ?? 'generation_failed';
        $generation->error_message = $snapshot->errorMessage;
        $generation->completed_at = $snapshot->completedAt ?? now();
    }
}
