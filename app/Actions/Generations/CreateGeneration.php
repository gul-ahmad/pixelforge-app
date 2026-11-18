<?php

namespace App\Actions\Generations;

use App\Actions\Credits\RecordCreditTransaction;
use App\Enums\CreditTransactionType;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PricingUnavailableException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saves a generation locally as pending, holds its maximum price from the user's balance,
 * then queues the submission to Pixelforge. The user never waits on Pixelforge here.
 *
 * Everything happens in one transaction with the user's row locked:
 *  - a repeated POST of the same form (client_request_id) returns the generation it already
 *    created instead of making, holding and billing a second one;
 *  - two tabs submitting at once can't both spend the same balance;
 *  - if anything fails, neither the generation nor the hold exists.
 *
 * The Pixelforge Idempotency-Key (one per generation) separately protects the job's retries.
 */
class CreateGeneration
{
    public function __construct(
        private readonly EstimateGenerationCost $estimate,
        private readonly RecordCreditTransaction $recordCredit,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws PricingUnavailableException
     * @throws InsufficientCreditsException
     */
    public function handle(
        User $user,
        string $clientRequestId,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        array $referenceImages = [],
    ): Generation {
        $estimate = $this->estimate->handle(mb_strlen($prompt, 'UTF-8'), count($referenceImages), $size, $quality);

        return DB::transaction(function () use ($user, $clientRequestId, $prompt, $size, $quality, $referenceImages, $estimate) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $alreadyCreated = Generation::query()
                ->where('user_id', $locked->id)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($alreadyCreated !== null) {
                return $alreadyCreated;
            }

            // Blocked unless the balance covers the most it could cost.
            if ($locked->balance_micro_usd < $estimate->maxMicroUsd) {
                throw new InsufficientCreditsException($estimate->maxMicroUsd, $locked->balance_micro_usd);
            }

            $generation = $locked->generations()->make([
                'client_request_id' => $clientRequestId,
                // One key per local generation, reused on every retry of its submission.
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_min_micro_usd' => $estimate->minMicroUsd,
                'estimated_max_micro_usd' => $estimate->maxMicroUsd,
            ]);
            $generation->forceFill(['reserved_micro_usd' => $estimate->maxMicroUsd])->save();

            $this->recordCredit->handle($locked->id, CreditTransactionType::Hold, -$estimate->maxMicroUsd, $generation->id);

            // afterCommit(): only queued once this transaction has committed.
            SubmitGeneration::dispatch($generation);

            return $generation;
        });
    }
}
