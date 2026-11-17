<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the browser sees of a generation. Money leaves the server as pre-formatted
 * strings, so the UI never does arithmetic on amounts. Provider cost stays internal.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageExpired = $this->imageUrlHasExpired();

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'status' => $this->status->value,
            'is_terminal' => $this->status->isTerminal(),
            'estimate' => [
                'min' => Pricing::formatMicroUsd($this->estimated_min_micro_usd),
                'max' => Pricing::formatMicroUsd($this->estimated_max_micro_usd),
            ],
            'charged' => $this->charged_micro_usd === null ? null : Pricing::formatMicroUsd($this->charged_micro_usd),
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
