<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyProviderUpdate;
use App\Actions\Generations\RejectGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\ProviderGeneration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge (POST /v1/generations) and handles every answer.
 *
 * Retry policy (all retries reuse the generation's Idempotency-Key, so none can double-bill):
 *  - 202                         → ApplyProviderUpdate (status may already be past queued on a replay).
 *  - 429 concurrency limit       → not a failure, just "no free slot yet": wait Retry-After, for up to
 *                                  SLOT_WAIT_MINUTES, then reject. Doesn't count towards the attempt cap.
 *  - other 429s / 503 overloaded → nothing was created: back off (≥ Retry-After), max MAX_ATTEMPTS,
 *                                  then reject. (A 429 always means "not created", even without a code.)
 *  - network error / other 5xx   → outcome UNKNOWN (Pixelforge may have created it): back off and retry;
 *                                  after MAX_ATTEMPTS stay pending (never reject), the poller retries later.
 *  - any other 4xx (policy, validation, auth, daily limit, …) → reject now, no retry.
 */
class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int MAX_ATTEMPTS = 5;

    public const int SLOT_WAIT_MINUTES = 15;

    private const int MAX_BACKOFF_SECONDS = 60;

    /** Releases are governed by the policy above and retryUntil(), not by a fixed try count. */
    public int $tries = 0;

    /** Unexpected exceptions (bugs, bad data) fail the job after this many. */
    public int $maxExceptions = 3;

    /** One submitter per generation at a time (the poller may re-dispatch). */
    public int $uniqueFor = 1800;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Generation $generation)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    /** Hard stop for the whole retry loop. Pending rows left after this are picked up by the poller. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(self::SLOT_WAIT_MINUTES + 10);
    }

    public function handle(PixelforgeClient $client, ApplyProviderUpdate $applyUpdate, RejectGeneration $reject): void
    {
        $generation = $this->generation->refresh();

        // A webhook, a replay or an earlier run already moved it on: nothing to submit.
        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        if ($generation->submitted_at === null) {
            $generation->forceFill(['submitted_at' => now()])->save();
        }

        try {
            $snapshot = $client->createGeneration($this->payload($generation), $generation->idempotency_key);
        } catch (PixelforgeApiException $e) {
            $this->handleApiError($generation, $e, $reject);

            return;
        } catch (ConnectionException $e) {
            $this->countAttempt($generation);
            $this->retryUnknownOutcome($generation, 'network_error', $e->getMessage());

            return;
        }

        $this->countAttempt($generation);
        $applyUpdate->handle($snapshot);
    }

    public function failed(?Throwable $exception): void
    {
        // The row stays pending; the poller re-dispatches stuck pending generations.
        Log::error('SubmitGeneration failed unexpectedly.', [
            'generation_id' => $this->generation->id,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * Only parameters Pixelforge knows: unknown ones are rejected with 422.
     *
     * @return array<string, mixed>
     */
    private function payload(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            // Lets a webhook that beats the create response find our row.
            'metadata' => [ProviderGeneration::METADATA_KEY => $generation->id],
        ];

        if ($generation->reference_images !== []) {
            $payload['reference_images'] = $generation->reference_images;
        }

        return $payload;
    }

    private function handleApiError(Generation $generation, PixelforgeApiException $e, RejectGeneration $reject): void
    {
        if ($e->errorCode === 'concurrency_limit_exceeded') {
            $this->waitForFreeSlot($generation, $e, $reject);

            return;
        }

        $this->countAttempt($generation);

        $isTemporaryRefusal = $e->errorCode === 'overloaded'
            || ($e->status === 429 && $e->errorCode !== 'daily_limit_exceeded');

        if ($isTemporaryRefusal) {
            $this->retryOrReject($generation, $e, $reject);
        } elseif ($e->isOutcomeUnknown()) {
            $this->retryUnknownOutcome($generation, $e->errorCode ?? 'server_error', $e->getMessage());
        } else {
            $this->rejectPermanently($generation, $e, $reject);
        }
    }

    private function waitForFreeSlot(Generation $generation, PixelforgeApiException $e, RejectGeneration $reject): void
    {
        $waitingSince = $generation->submitted_at ?? $generation->created_at ?? now();

        if ($waitingSince->copy()->addMinutes(self::SLOT_WAIT_MINUTES)->isPast()) {
            $reject->handle($generation, 'concurrency_limit_exceeded',
                'Too many images were generating at once and no slot freed up in time. You were not charged. Please try again.');

            return;
        }

        $this->noteRetry($generation, 'concurrency_limit_exceeded', 'Waiting for a free generation slot…');
        $this->release($e->retryAfterSeconds ?? 5);
    }

    /** Rate limited or overloaded: Pixelforge guarantees nothing was created, so giving up is safe. */
    private function retryOrReject(Generation $generation, PixelforgeApiException $e, RejectGeneration $reject): void
    {
        $code = $e->errorCode ?? "http_{$e->status}";

        if ($generation->submit_attempts >= self::MAX_ATTEMPTS) {
            $reject->handle($generation, $code,
                'Pixelforge was too busy to accept this generation. You were not charged. Please try again.');

            return;
        }

        $this->noteRetry($generation, $code, 'Pixelforge is busy, retrying…');
        $this->release(max($e->retryAfterSeconds ?? 0, $this->backoffSeconds($generation)));
    }

    /**
     * We don't know if Pixelforge created it, so we must NOT reject (it may be billed).
     * Retrying with the same Idempotency-Key is always safe: at worst it replays.
     */
    private function retryUnknownOutcome(Generation $generation, string $code, string $detail): void
    {
        Log::warning('Pixelforge create had an unknown outcome.', [
            'generation_id' => $generation->id,
            'code' => $code,
            'detail' => $detail,
            'attempt' => $generation->submit_attempts,
        ]);

        $this->noteRetry($generation, $code, 'Could not reach Pixelforge, retrying…');

        if ($generation->submit_attempts < self::MAX_ATTEMPTS) {
            $this->release($this->backoffSeconds($generation));
        }
    }

    private function rejectPermanently(Generation $generation, PixelforgeApiException $e, RejectGeneration $reject): void
    {
        $message = match ($e->errorCode) {
            'content_policy_violation' => 'Your prompt was blocked by the content policy. Try rewording it. You were not charged.',
            'validation_failed' => "Pixelforge rejected the request: {$e->getMessage()} You were not charged.",
            'daily_limit_exceeded' => "Today's generation limit has been reached. Please try again after midnight UTC. You were not charged.",
            default => 'Image generation is temporarily unavailable. You were not charged.',
        };

        // Our own bugs or configuration problems: make them loud for operators.
        if (in_array($e->errorCode, ['invalid_api_key', 'idempotency_key_reused', 'validation_failed'], true) || $e->errorCode === null) {
            Log::critical('Pixelforge rejected a generation because of our request or configuration.', [
                'generation_id' => $generation->id,
                'status' => $e->status,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
        }

        $reject->handle($generation, $e->errorCode ?? "http_{$e->status}", $message);
    }

    /** Counts an attempt atomically (safe alongside a concurrent webhook writing the same row). */
    private function countAttempt(Generation $generation): void
    {
        Generation::query()->whereKey($generation->id)->increment('submit_attempts');
        $generation->submit_attempts++;
    }

    /** A user-facing "retrying…" note, written only while still pending so it can't clobber newer state. */
    private function noteRetry(Generation $generation, string $code, string $message): void
    {
        Generation::query()
            ->whereKey($generation->id)
            ->where('status', GenerationStatus::Pending)
            ->update(['error_code' => $code, 'error_message' => $message]);
    }

    /** 2, 4, 8, 16, 32 … seconds, capped. */
    private function backoffSeconds(Generation $generation): int
    {
        return min(self::MAX_BACKOFF_SECONDS, 2 ** max(1, $generation->submit_attempts));
    }
}
