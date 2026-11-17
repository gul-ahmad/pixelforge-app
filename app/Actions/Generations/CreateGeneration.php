<?php

namespace App\Actions\Generations;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Saves a generation locally as pending, with its estimate and its own idempotency key,
 * then queues the submission to Pixelforge. The user never waits on Pixelforge here.
 *
 * Two layers of "never twice":
 *  - client_request_id (one per rendered form): a repeated POST of the same form returns
 *    the generation it already created instead of making a second paid one.
 *  - idempotency_key (one per generation): retries of the Pixelforge call can't create
 *    a second generation at Pixelforge.
 */
class CreateGeneration
{
    public function __construct(private readonly EstimateGenerationCost $estimate) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws PricingUnavailableException
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

        // Insert, or return the row this form already created (race-safe via the unique index).
        $generation = $user->generations()->createOrFirst(['client_request_id' => $clientRequestId], [
            // One key per local generation, reused on every retry of its submission.
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'estimated_min_micro_usd' => $estimate->minMicroUsd,
            'estimated_max_micro_usd' => $estimate->maxMicroUsd,
        ]);

        if ($generation->wasRecentlyCreated) {
            SubmitGeneration::dispatch($generation);
        }

        return $generation;
    }
}
