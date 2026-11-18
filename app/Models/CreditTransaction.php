<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the append-only credit ledger. Created only by RecordCreditTransaction;
 * never updated or deleted, so the history always explains the balance.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property CreditTransactionType $type
 * @property int $amount_micro_usd
 * @property int $balance_after_micro_usd
 * @property string|null $idempotency_key
 * @property CarbonImmutable $created_at
 * @property-read Generation|null $generation
 */
class CreditTransaction extends Model
{
    /** Append-only: there is no "updated" moment. */
    public const null UPDATED_AT = null;

    /** Nothing is mass assignable; the recording action sets fields explicitly. */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // Belt and braces for "append-only": refuse edits and deletes in code too.
        static::updating(fn () => throw new LogicException('Credit transactions are append-only.'));
        static::deleting(fn () => throw new LogicException('Credit transactions are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_micro_usd' => 'integer',
            'balance_after_micro_usd' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
