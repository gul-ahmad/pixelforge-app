<?php

namespace App\Actions\Credits;

use App\Enums\CreditTransactionType;
use App\Models\Generation;

/**
 * Moves a generation's money on the ledger when it ends. Called only from inside the
 * locked, forward-only transitions (ApplyProviderUpdate, RejectGeneration), so it runs at
 * most once per outcome; the unique (generation_id, type) index is the database backstop.
 *
 *   succeeded:         release the hold (+max), then charge the actual cost (−actual)
 *   failed / rejected: release the hold (+max), nothing charged
 *
 * The caller saves the generation (reserved_micro_usd is set back to 0 here).
 */
class SettleGenerationCredits
{
    public function __construct(private readonly RecordCreditTransaction $record) {}

    public function charge(Generation $generation): void
    {
        $this->release($generation);

        if ($generation->user_id !== null && (int) $generation->charged_micro_usd > 0) {
            $this->record->handle(
                $generation->user_id,
                CreditTransactionType::Charge,
                -(int) $generation->charged_micro_usd,
                $generation->id,
            );
        }
    }

    /**
     * Gives the held maximum back. Does nothing if there is no hold (already released, or a
     * generation from before balances existed).
     */
    public function release(Generation $generation): void
    {
        if ($generation->user_id === null || $generation->reserved_micro_usd <= 0) {
            return;
        }

        $this->record->handle(
            $generation->user_id,
            CreditTransactionType::Release,
            $generation->reserved_micro_usd,
            $generation->id,
        );

        $generation->reserved_micro_usd = 0;
    }
}
