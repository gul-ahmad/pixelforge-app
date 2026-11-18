<?php

namespace App\Http\Resources;

use App\Models\CreditTransaction;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ledger row as the user sees it. Amounts leave the server pre-formatted.
 *
 * @mixin CreditTransaction
 */
class CreditTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'amount' => ($this->amount_micro_usd >= 0 ? '+' : '').Pricing::formatMicroUsd($this->amount_micro_usd),
            'is_credit' => $this->amount_micro_usd >= 0,
            'balance_after' => Pricing::formatMicroUsd($this->balance_after_micro_usd),
            'generation_prompt' => $this->generation?->prompt,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
