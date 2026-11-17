<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One id per rendered generate form. A double-click or a browser retrying the POST sends
     * the same id again, and the unique index makes sure it creates (and bills) only one
     * generation. Scoped per user, so one user's ids can never collide with another's.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->uuid('client_request_id')->nullable()->after('idempotency_key');
            $table->unique(['user_id', 'client_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_request_id']);
            $table->dropColumn('client_request_id');
        });
    }
};
