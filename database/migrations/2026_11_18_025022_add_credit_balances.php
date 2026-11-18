<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit balances (stretch goal). Money is still integer micro-USD.
     *
     *  - credit_transactions: append-only ledger. Every balance change is a new row; rows are
     *    never updated or deleted, so the history explains every cent.
     *  - users.balance_micro_usd: running total, updated in the same transaction as each row.
     *    Signed: it can only go below zero in one documented edge case (a generation we had
     *    rejected turns out to exist at Pixelforge and succeeds; it must still be paid for).
     *  - generations.reserved_micro_usd: the hold currently placed for this generation
     *    (its maximum estimate), back to 0 once released.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('balance_micro_usd')->default(0)->after('is_admin');
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedBigInteger('reserved_micro_usd')->default(0)->after('estimated_max_micro_usd');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);                       // purchase | hold | release | charge
            $table->bigInteger('amount_micro_usd');           // + adds to the balance, − takes from it
            $table->bigInteger('balance_after_micro_usd');
            // Purchases only: one id per rendered "Buy credits" form, so a double-click buys once.
            $table->uuid('idempotency_key')->nullable();
            $table->timestamp('created_at');

            // The database itself guarantees one hold, one release and one charge per generation.
            $table->unique(['generation_id', 'type']);
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('reserved_micro_usd');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance_micro_usd');
        });
    }
};
