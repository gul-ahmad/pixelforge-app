<?php

namespace App\Enums;

/**
 * Kinds of credit ledger entries. For one generation the ledger reads:
 *   hold −max  →  release +max  →  charge −actual   (succeeded: pays exactly the actual cost)
 *   hold −max  →  release +max                      (failed / rejected: free)
 */
enum CreditTransactionType: string
{
    case Purchase = 'purchase';   // + credits bought (fake purchase)
    case Hold = 'hold';           // − maximum estimate set aside when submitting
    case Release = 'release';     // + that hold given back when the generation ends
    case Charge = 'charge';       // − the actual charge of a succeeded generation

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Credits bought',
            self::Hold => 'Held for generation',
            self::Release => 'Hold released',
            self::Charge => 'Charged for generation',
        };
    }
}
