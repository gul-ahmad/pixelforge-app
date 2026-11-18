<?php

namespace App\Support;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * All money maths in one place. No floats anywhere: amounts are integers in fixed units
 * and every calculation goes through BigDecimal.
 *
 * Units:
 *  - nano-USD  (1e-9 $): Pixelforge's exact cost. GET /v1/usage reports 9 decimal places,
 *                        so this scale lets us reconcile with integer equality.
 *  - micro-USD (1e-6 $): what we charge the user.
 *
 * Rules:
 *  - Provider cost = Σ tokens × rate per 1M tokens, exact (never rounded).
 *  - Customer charge = provider cost × 1.25, rounded HALF-UP to whole micro-dollars.
 */
final class Pricing
{
    /** Our markup on Pixelforge's cost: +25%. */
    public const string MARKUP = '1.25';

    /** The token types Pixelforge bills, matching the keys of `usage` and `rates`. */
    public const array TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /** Rates are dollars per 1M tokens; × 1,000 turns "tokens × rate" (micro-$) into nano-$. */
    private const int NANO_PER_MICRO = 1_000;

    /**
     * Exact Pixelforge cost in nano-USD.
     *
     * tokens × rate gives micro-dollars (rate is $ per 1,000,000 tokens), × 1,000 gives nano-dollars.
     * With rates of up to 3 decimal places this is always a whole number; if Pixelforge ever
     * published finer rates, toScale(Unnecessary) throws instead of silently rounding money.
     *
     * @param  array<string, mixed>  $usage  from the generation object (extra keys such as total_tokens are ignored)
     * @param  array<string, mixed>  $rates  from the same generation object
     */
    public static function providerCostNanoUsd(array $usage, array $rates): int
    {
        $total = BigDecimal::zero();

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = $usage[$type] ?? null;
            $rate = $rates[$type] ?? null;

            if (! is_int($tokens) || $tokens < 0) {
                throw new InvalidArgumentException("Usage [{$type}] must be a non-negative integer.");
            }

            if (! self::isDecimalString($rate)) {
                throw new InvalidArgumentException("Rate [{$type}] must be a non-negative decimal string.");
            }

            $total = $total->plus(BigDecimal::of($rate)->multipliedBy($tokens));
        }

        return $total->multipliedBy(self::NANO_PER_MICRO)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toInt();
    }

    /**
     * A plain non-negative decimal string such as "5.00" or "12.5".
     * Stricter than is_numeric(): no floats, signs, exponents or whitespace in money input.
     *
     * @phpstan-assert-if-true numeric-string $value
     */
    public static function isDecimalString(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1;
    }

    /**
     * What we charge the user, in micro-USD: cost × 1.25, rounded half-up to the micro-dollar.
     *
     * Example: 373,875,000 nano × 1.25 = 467,343,750 nano = 467,343.75 micro → 467,344 micro ($0.467344).
     */
    public static function customerChargeMicroUsd(int $providerCostNanoUsd): int
    {
        if ($providerCostNanoUsd < 0) {
            throw new InvalidArgumentException('Provider cost cannot be negative.');
        }

        return BigDecimal::of($providerCostNanoUsd)
            ->multipliedBy(self::MARKUP)
            ->dividedBy(self::NANO_PER_MICRO, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * Price range for a generation before it is submitted, including markup.
     *
     * Known exactly up front: image tokens (765 each) and output tokens (by size and quality).
     * Only known afterwards: text tokens (≈ ceil(chars / 4), real count within ±15%) and
     * reasoning tokens (a range per quality). The range uses the lowest and highest values of
     * both. Half-up rounding never decreases a value, so the real charge always lies inside it.
     */
    public static function estimate(
        PricingSheet $sheet,
        int $promptCharacters,
        int $referenceImageCount,
        ImageSize $size,
        ImageQuality $quality,
    ): CostEstimate {
        if ($referenceImageCount < 0) {
            throw new InvalidArgumentException('Reference image count cannot be negative.');
        }

        [$minTextTokens, $maxTextTokens] = self::textTokenRange($sheet, $promptCharacters);
        $imageTokens = $referenceImageCount * $sheet->tokensPerReferenceImage;
        $outputTokens = $sheet->outputTokensFor($quality, $size);
        $reasoning = $sheet->reasoningRange($quality);

        $minTokens = [
            'input_text_tokens' => $minTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['min'],
        ];

        $maxTokens = [
            'input_text_tokens' => $maxTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['max'],
        ];

        return new CostEstimate(
            minMicroUsd: self::customerChargeMicroUsd(self::providerCostNanoUsd($minTokens, $sheet->rates)),
            maxMicroUsd: self::customerChargeMicroUsd(self::providerCostNanoUsd($maxTokens, $sheet->rates)),
            minTokens: $minTokens,
            maxTokens: $maxTokens,
        );
    }

    /**
     * Estimated text tokens are ceil(characters / 4), where characters are Unicode characters
     * (mb_strlen), not bytes. The real count is within ±variance of that, so:
     * floor(est × 0.85) … ceil(est × 1.15).
     *
     * @return array{0: int, 1: int}
     */
    public static function textTokenRange(PricingSheet $sheet, int $characters): array
    {
        if ($characters < 0) {
            throw new InvalidArgumentException('Character count cannot be negative.');
        }

        $estimate = intdiv($characters + $sheet->charactersPerTextToken - 1, $sheet->charactersPerTextToken);
        $variance = BigDecimal::of($sheet->textTokenVariance);

        $min = BigDecimal::of($estimate)->multipliedBy(BigDecimal::one()->minus($variance))
            ->toScale(0, RoundingMode::Floor)
            ->toInt();
        $max = BigDecimal::of($estimate)->multipliedBy(BigDecimal::one()->plus($variance))
            ->toScale(0, RoundingMode::Ceiling)
            ->toInt();

        return [max(0, $min), $max];
    }

    /**
     * Display a micro-USD amount exactly, e.g. 467344 → "$0.467344", 11200 → "$0.0112", 1500000 → "$1.50".
     * Trailing zeros are trimmed but at least 2 decimals are kept; nothing is ever rounded away.
     */
    public static function formatMicroUsd(int $microUsd): string
    {
        $sign = $microUsd < 0 ? '-' : '';
        $fixed = (string) BigDecimal::ofUnscaledValue(abs($microUsd), 6);   // e.g. "0.011200"
        [$whole, $fraction] = explode('.', $fixed);
        $fraction = str_pad(rtrim($fraction, '0'), 2, '0');

        return "{$sign}\${$whole}.{$fraction}";
    }

    /** Display a nano-USD amount with all 9 decimals, the same format as GET /v1/usage. */
    public static function formatNanoUsd(int $nanoUsd): string
    {
        return (string) BigDecimal::ofUnscaledValue($nanoUsd, 9);
    }

    /** "$0.012590000" (9 decimals, like Pixelforge's usage report); negatives as "-$…". */
    public static function formatNanoUsdWithSymbol(int $nanoUsd): string
    {
        $sign = $nanoUsd < 0 ? '-' : '';

        return $sign.'$'.self::formatNanoUsd(abs($nanoUsd));
    }

    /**
     * Pixelforge's "cost_usd" (an exact decimal string, e.g. "0.247690000") as nano-USD.
     * Throws rather than rounding if it ever had more than 9 decimals.
     */
    public static function usdStringToNano(string $usd): int
    {
        if (! self::isDecimalString($usd)) {
            throw new InvalidArgumentException("Not a decimal USD amount: [{$usd}].");
        }

        return BigDecimal::of($usd)->multipliedBy(1_000_000_000)->toScale(0, RoundingMode::Unnecessary)->toInt();
    }

    /** Micro-USD (what users are charged) in nano-USD, so it can be compared with provider costs. */
    public static function microToNano(int $microUsd): int
    {
        return $microUsd * self::NANO_PER_MICRO;
    }
}
