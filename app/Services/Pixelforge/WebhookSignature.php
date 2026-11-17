<?php

namespace App\Services\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix timestamp>,v1=<hex HMAC-SHA256>".
 *
 *   expected = hex( HMAC-SHA256( secret, "{t}.{raw request body}" ) )
 *
 * - Always the RAW body: re-encoding JSON could change spacing or key order and break the HMAC.
 * - Compared with hash_equals (constant time), so response timing leaks nothing about the secret.
 * - The signed timestamp must be within the tolerance (both directions) to stop replays of an
 *   old, captured request. Each delivery attempt is signed with a fresh timestamp.
 */
final class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $rawBody, ?string $header, int $now): bool
    {
        $parts = self::parse($header);

        if ($parts === null) {
            return false;
        }

        [$timestamp, $signature] = $parts;

        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        return hash_equals(self::sign($this->secret, $timestamp, $rawBody), $signature);
    }

    /** Builds the hex signature; also used by tests to create valid requests. */
    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    /**
     * @return array{0: int, 1: string}|null [timestamp, v1 signature], or null if malformed
     */
    private static function parse(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }

        $values = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $values[$key] ??= $value;
        }

        $timestamp = $values['t'] ?? '';
        $signature = $values['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return null;
        }

        return [(int) $timestamp, $signature];
    }
}
