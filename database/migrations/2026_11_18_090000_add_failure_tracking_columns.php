<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Set once any create attempt ended without a definite answer (network error, unexpected 5xx).
            // From then on Pixelforge may have created it, so we never reject it: we keep replaying the
            // same Idempotency-Key (keys never expire) until Pixelforge gives a definite answer.
            $table->boolean('submit_outcome_unknown')->default(false)->after('submit_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('submit_outcome_unknown');
        });
    }
};
