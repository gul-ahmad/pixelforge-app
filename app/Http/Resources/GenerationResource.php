<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
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
            'status_label' => $this->status->label(),
            'status_message' => $this->statusMessage(),
            // Still pending, but the last attempt hit a temporary problem (shown as a warning).
            'is_retrying' => $this->status === GenerationStatus::Pending && $this->error_code !== null,
            'is_terminal' => $this->status->isTerminal(),
            'estimate' => [
                'min' => Pricing::formatMicroUsd($this->estimated_min_micro_usd),
                'max' => Pricing::formatMicroUsd($this->estimated_max_micro_usd),
            ],
            'charged' => $this->charged_micro_usd === null ? null : Pricing::formatMicroUsd($this->charged_micro_usd),
            'charge_note' => $this->chargeNote(),
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /** One plain sentence saying what is happening or what went wrong. */
    private function statusMessage(): ?string
    {
        return match ($this->status) {
            GenerationStatus::Pending => $this->error_message ?? 'Sending to Pixelforge…',
            GenerationStatus::Queued => "Waiting in Pixelforge's queue…",
            GenerationStatus::Processing => 'Generating your image…',
            GenerationStatus::Succeeded => null,
            GenerationStatus::Failed => match ($this->error_code) {
                'generation_failed' => "Pixelforge couldn't produce an image for this prompt. You were not charged.",
                'internal_error' => 'Failed during generation because of a problem at Pixelforge. You were not charged. Trying again usually works.',
                default => 'Failed during generation. You were not charged.',
            },
            GenerationStatus::Rejected => $this->error_message ?? 'Pixelforge did not accept this generation. You were not charged.',
        };
    }

    /** Explains the charge column so a missing number is never ambiguous. */
    private function chargeNote(): string
    {
        return match ($this->status) {
            GenerationStatus::Succeeded => 'Actual cost incl. 25% fee',
            GenerationStatus::Failed, GenerationStatus::Rejected => 'Not charged',
            default => 'Charged when finished',
        };
    }
}
