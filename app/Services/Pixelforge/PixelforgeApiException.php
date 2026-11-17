<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A non-2xx answer from Pixelforge. Errors always look like
 * {"error": {"type", "code", "message", "param"?, "errors"?}}; we branch on `code`.
 */
final class PixelforgeApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');
        $retryAfter = $response->header('Retry-After');

        return new self(
            status: $response->status(),
            errorCode: is_string($code) ? $code : null,
            message: is_string($message) ? $message : "Pixelforge returned HTTP {$response->status()}.",
            retryAfterSeconds: is_numeric($retryAfter) ? max(0, (int) $retryAfter) : null,
        );
    }

    /**
     * True when we cannot know whether Pixelforge created the generation
     * (a 5xx other than the documented `overloaded`). Must never be treated as "not created".
     */
    public function isOutcomeUnknown(): bool
    {
        return $this->status >= 500 && $this->errorCode !== 'overloaded';
    }
}
