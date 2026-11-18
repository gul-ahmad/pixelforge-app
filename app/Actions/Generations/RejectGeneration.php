<?php

namespace App\Actions\Generations;

use App\Actions\Credits\SettleGenerationCredits;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

/**
 * Marks a generation as rejected: Pixelforge refused it before creating anything,
 * so it was never billed and costs the user nothing.
 *
 * Uses the same lock and the same transition rule as ApplyProviderUpdate, so a rejection
 * can never overwrite a generation Pixelforge has meanwhile accepted.
 * The hold placed on the user's balance is given back in the same transaction.
 */
class RejectGeneration
{
    public function __construct(private readonly SettleGenerationCredits $settleCredits) {}

    public function handle(Generation $generation, string $errorCode, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $message) {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(GenerationStatus::Rejected)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => GenerationStatus::Rejected,
                'error_code' => $errorCode,
                'error_message' => $message,
                'provider_cost_nano_usd' => 0,
                'charged_micro_usd' => 0,
            ]);

            $this->settleCredits->release($locked);
            $locked->save();

            return $locked;
        });
    }
}
