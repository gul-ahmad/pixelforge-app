<?php

namespace App\Enums;

/**
 * Local lifecycle of a generation.
 *
 *   pending ──▶ queued ──▶ processing ──▶ succeeded
 *      │                        └───────▶ failed
 *      └──▶ rejected
 *
 * queued/processing/succeeded/failed mirror Pixelforge's own statuses.
 * pending and rejected only exist locally:
 *  - pending:  saved by us, not yet accepted by Pixelforge.
 *  - rejected: Pixelforge refused it before creating anything (never billed).
 *
 * Status only moves forward. Late or out-of-order provider events must never move a
 * generation back, and succeeded/failed are final.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * Map a status string from the Pixelforge API to our enum.
     * Only the four provider statuses are accepted; anything else is a bug or an API change.
     */
    public static function fromProvider(string $status): self
    {
        return match ($status) {
            'queued' => self::Queued,
            'processing' => self::Processing,
            'succeeded' => self::Succeeded,
            'failed' => self::Failed,
            default => throw new \UnexpectedValueException("Unknown Pixelforge status [{$status}]."),
        };
    }

    /**
     * Whether a generation in this status may move to $next.
     *
     * Rejected → provider status is deliberately allowed: if Pixelforge tells us about a
     * generation we had given up on, it really exists (and may be billed), so the
     * provider's truth wins over our local guess.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next !== self::Pending,
            self::Queued => in_array($next, [self::Processing, self::Succeeded, self::Failed], true),
            self::Processing => in_array($next, [self::Succeeded, self::Failed], true),
            self::Rejected => $next->isProviderStatus(),
            self::Succeeded, self::Failed => false,
        };
    }

    /** No further change is expected (polling and UI live updates can stop). */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Rejected], true);
    }

    /** Accepted by Pixelforge and still running: candidates for polling. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }

    public function isProviderStatus(): bool
    {
        return in_array($this, [self::Queued, self::Processing, self::Succeeded, self::Failed], true);
    }
}
