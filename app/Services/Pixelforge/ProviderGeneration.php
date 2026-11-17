<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A validated snapshot of Pixelforge's generation object, as returned by
 * POST/GET /v1/generations and inside every webhook (data.object).
 *
 * Every source of provider data is turned into this one shape before
 * ApplyProviderUpdate looks at it, so there is only one parser to trust.
 */
final readonly class ProviderGeneration
{
    /** The metadata key we send on create, holding our local generation id. */
    public const string METADATA_KEY = 'generation_id';

    /**
     * @param  array<string, mixed>  $rates  $ per 1M tokens, fixed when the generation was created
     * @param  array<string, mixed>|null  $usage  null until succeeded
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?string $localGenerationId,
        public array $rates,
        public ?array $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || ! str_starts_with($id, 'gen_')) {
            throw new InvalidArgumentException('Pixelforge generation has no valid id.');
        }

        $status = GenerationStatus::fromProvider(is_string($data['status'] ?? null) ? $data['status'] : '');
        $rates = $data['rates'] ?? null;
        $usage = $data['usage'] ?? null;
        $error = $data['error'] ?? null;
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $localId = $metadata[self::METADATA_KEY] ?? null;

        if (! is_array($rates)) {
            throw new InvalidArgumentException("Pixelforge generation {$id} has no rates.");
        }

        // A succeeded generation without usage cannot be priced: refuse it loudly.
        if ($status === GenerationStatus::Succeeded && ! is_array($usage)) {
            throw new InvalidArgumentException("Succeeded generation {$id} has no usage.");
        }

        return new self(
            id: $id,
            status: $status,
            localGenerationId: is_string($localId) ? $localId : null,
            rates: $rates,
            usage: is_array($usage) ? $usage : null,
            imageUrl: is_string($data['image_url'] ?? null) ? $data['image_url'] : null,
            imageUrlExpiresAt: self::time($data['image_url_expires_at'] ?? null),
            errorCode: is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null,
            errorMessage: is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null,
            startedAt: self::time($data['started_at'] ?? null),
            completedAt: self::time($data['completed_at'] ?? null),
        );
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
