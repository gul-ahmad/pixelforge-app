<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every verified webhook event, stored once.
     * The unique provider_event_id is what makes duplicate deliveries harmless.
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 64)->unique();
            $table->string('type', 64);
            $table->string('provider_generation_id', 64)->nullable()->index();
            $table->unsignedInteger('delivery_attempt')->default(1);
            $table->json('payload');
            $table->timestamp('received_at');
            // Null until ApplyProviderUpdate has handled it; lets a redelivery re-dispatch lost work.
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
