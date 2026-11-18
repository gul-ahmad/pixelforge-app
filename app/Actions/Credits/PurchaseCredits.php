<?php

namespace App\Actions\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fake "Buy credits": adds a fixed pack to the balance. No payment provider is involved.
 * The form's id makes it idempotent: a double-click or a resent POST buys once.
 */
class PurchaseCredits
{
    /** Allowed packs, in micro-USD ($1, $5, $10, $20). Users can't choose arbitrary amounts. */
    public const array PACKS_MICRO_USD = [1_000_000, 5_000_000, 10_000_000, 20_000_000];

    public function __construct(private readonly RecordCreditTransaction $record) {}

    public function handle(User $user, int $packMicroUsd, string $idempotencyKey): CreditTransaction
    {
        if (! in_array($packMicroUsd, self::PACKS_MICRO_USD, true)) {
            throw new InvalidArgumentException('Unknown credit pack.');
        }

        return DB::transaction(function () use ($user, $packMicroUsd, $idempotencyKey) {
            // Lock the user first, so two copies of the same request run one after the other
            // and the second one finds the first one's row.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = CreditTransaction::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            return $existing ?? $this->record->handle(
                $user->id,
                CreditTransactionType::Purchase,
                $packMicroUsd,
                idempotencyKey: $idempotencyKey,
            );
        });
    }
}
