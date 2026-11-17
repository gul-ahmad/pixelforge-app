<?php

namespace App\Support;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use InvalidArgumentException;

/**
 * The response of GET /v1/pricing, validated once into a typed object.
 *
 * Used only for estimates. Real charges use the rates stored on each generation,
 * which Pixelforge fixes when the generation is created.
 */
final readonly class PricingSheet
{
    /**
     * @param  array<string, string>  $rates  $ per 1M tokens, decimal strings keyed by token type
     * @param  string  $textTokenVariance  e.g. "0.15" (±15%), kept as a string so it never becomes a float
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => exact output tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * Build from the decoded JSON of GET /v1/pricing. Throws if anything we rely on is missing,
     * so a changed API fails loudly instead of producing a wrong estimate.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $rates = $data['rates'] ?? null;
        $tokens = $data['tokens'] ?? null;

        if (! is_array($rates) || ! is_array($tokens)) {
            throw new InvalidArgumentException('Pricing response is missing rates or tokens.');
        }

        $sheet = new self(
            rates: self::stringRates($rates),
            charactersPerTextToken: self::positiveInt($tokens['characters_per_text_token'] ?? null, 'characters_per_text_token'),
            // json_decode turns 0.15 into a float; we immediately convert it back to the string "0.15".
            textTokenVariance: self::decimalString($tokens['text_token_variance'] ?? null, 'text_token_variance'),
            tokensPerReferenceImage: self::positiveInt($tokens['per_reference_image'] ?? null, 'per_reference_image'),
            outputTokens: is_array($tokens['output'] ?? null) ? $tokens['output'] : [],
            reasoningTokens: is_array($tokens['reasoning'] ?? null) ? $tokens['reasoning'] : [],
        );

        // Fail now (not at estimate time) if any size/quality we offer is missing.
        foreach (ImageQuality::cases() as $quality) {
            $sheet->reasoningRange($quality);

            foreach (ImageSize::cases() as $size) {
                $sheet->outputTokensFor($quality, $size);
            }
        }

        return $sheet;
    }

    public function outputTokensFor(ImageQuality $quality, ImageSize $size): int
    {
        $tokens = $this->outputTokens[$quality->value][$size->value] ?? null;

        if (! is_int($tokens) || $tokens < 0) {
            throw new InvalidArgumentException("No output token count for {$quality->value} {$size->value}.");
        }

        return $tokens;
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRange(ImageQuality $quality): array
    {
        $range = $this->reasoningTokens[$quality->value] ?? null;
        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;

        if (! is_int($min) || ! is_int($max) || $min < 0 || $max < $min) {
            throw new InvalidArgumentException("No reasoning token range for {$quality->value}.");
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * @param  array<mixed>  $rates
     * @return array<string, string>
     */
    private static function stringRates(array $rates): array
    {
        $result = [];

        foreach (Pricing::TOKEN_TYPES as $type) {
            $rate = $rates[$type] ?? null;

            if (! Pricing::isDecimalString($rate)) {
                throw new InvalidArgumentException("Pricing rate [{$type}] is missing or not a decimal string.");
            }

            $result[$type] = $rate;
        }

        return $result;
    }

    private static function positiveInt(mixed $value, string $name): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException("Pricing value [{$name}] must be a positive integer.");
        }

        return $value;
    }

    private static function decimalString(mixed $value, string $name): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        // PHP 8 converts floats to their shortest exact-round-trip string, so 0.15 becomes "0.15".
        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException("Pricing value [{$name}] must be numeric.");
    }
}
