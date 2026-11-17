<?php

namespace App\Http\Controllers;

use App\Actions\Generations\EstimateGenerationCost;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Http\Requests\Generations\EstimateGenerationCostRequest;
use Illuminate\Http\JsonResponse;

/** Live estimate for the generate form (JSON). */
class EstimateGenerationCostController extends Controller
{
    public function __invoke(EstimateGenerationCostRequest $request, EstimateGenerationCost $estimate): JsonResponse
    {
        try {
            $result = $estimate->handle(
                $request->integer('characters'),
                $request->integer('reference_images'),
                $request->enum('size', ImageSize::class) ?? ImageSize::Square,
                $request->enum('quality', ImageQuality::class) ?? ImageQuality::Medium,
            );
        } catch (PricingUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($result->toArray());
    }
}
