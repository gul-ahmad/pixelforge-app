<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money columns are integers, never floats or DECIMAL:
     *  - *_micro_usd: millionths of a dollar (what the user is charged / estimated).
     *  - *_nano_usd:  billionths of a dollar (exact Pixelforge cost, same scale as GET /v1/usage).
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Nullable + nullOnDelete: if a user deletes their account, the money records stay
            // so our totals still reconcile with what Pixelforge billed us.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Sent as the Idempotency-Key header on every retry of this submission.
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_generation_id', 64)->nullable()->unique();

            // Indexed via the (status, last_synced_at) composite below.
            $table->string('status', 20);

            // What the user asked for.
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images');

            // Estimate shown before submit, including markup.
            $table->unsignedBigInteger('estimated_min_micro_usd');
            $table->unsignedBigInteger('estimated_max_micro_usd');

            // Audit trail: exactly what Pixelforge reported, so every charge can be recalculated.
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();

            // Set once, when the generation first becomes succeeded (0 for failed).
            $table->unsignedBigInteger('provider_cost_nano_usd')->nullable();
            $table->unsignedBigInteger('charged_micro_usd')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // History page: a user's generations, newest first.
            $table->index(['user_id', 'created_at']);
            // Polling: in-flight generations not synced recently.
            $table->index(['status', 'last_synced_at']);
            // Admin report / reconciliation: Pixelforge bills on the UTC day a generation completed.
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
