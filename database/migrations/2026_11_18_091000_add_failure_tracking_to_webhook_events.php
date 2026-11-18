<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            // How many full rounds of job tries have failed (a "poison" event fails every round).
            $table->unsignedInteger('process_attempts')->default(0)->after('payload');
            // Set when we stop retrying: an operator must look at it. Excluded from the retry query
            // so a handful of bad events can never starve genuinely lost ones.
            $table->timestamp('failed_at')->nullable()->after('processed_at');
            $table->index(['processed_at', 'failed_at', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex(['processed_at', 'failed_at', 'received_at']);
            $table->dropColumn(['process_attempts', 'failed_at']);
        });
    }
};
