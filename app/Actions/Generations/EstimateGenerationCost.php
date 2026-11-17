<?php

namespace App\Actions\Generations;

use App\Actions\Pricing\GetPricingSheet;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Support\CostEstimate;
use App\Support\Pricing;

/**
 * The price range shown before submitting. Used by both the live estimate on the form
 * and CreateGeneration, so the number the user saw is the number we store.
 */
class EstimateGenerationCost
{
    public function __construct(private readonly GetPricingSheet $pricing) {}

    /**
     * @param  int  $promptCharacters  Unicode characters (mb_strlen), not bytes
     *
     * @throws PricingUnavailableException
     */
    public function handle(int $promptCharacters, int $referenceImageCount, ImageSize $size, ImageQuality $quality): CostEstimate
    {
        return Pricing::estimate($this->pricing->handle(), $promptCharacters, $referenceImageCount, $size, $quality);
    }
}
