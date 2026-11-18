<?php

namespace App\Actions\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * THE only way a balance changes: append one ledger row and update the running total,
 * in one transaction, with the user's row locked. Two requests for the same user (two tabs,
 * a webhook and a poll…) therefore can't read the same balance and both spend it.
 *
 * If the caller is already in a transaction (e.g. ApplyProviderUpdate), this joins it, so
 * the generation's status change and its money move are committed together or not at all.
 */
class RecordCreditTransaction
{
    public function handle(
        int $userId,
        CreditTransactionType $type,
        int $amountMicroUsd,
        ?string $generationId = null,
        ?string $idempotencyKey = null,
    ): CreditTransaction {
        return DB::transaction(function () use ($userId, $type, $amountMicroUsd, $generationId, $idempotencyKey) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $balanceAfter = $user->balance_micro_usd + $amountMicroUsd;

            $transaction = new CreditTransaction;
            $transaction->forceFill([
                'user_id' => $user->id,
                'generation_id' => $generationId,
                'type' => $type,
                'amount_micro_usd' => $amountMicroUsd,
                'balance_after_micro_usd' => $balanceAfter,
                'idempotency_key' => $idempotencyKey,
            ])->save();

            $user->forceFill(['balance_micro_usd' => $balanceAfter])->save();

            return $transaction;
        });
    }
}
