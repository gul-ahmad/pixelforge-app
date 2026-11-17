<?php

namespace App\Support;

/**
 * A price range shown before submitting, including our markup.
 * The token ranges are kept so the UI can explain where the numbers come from.
 */
final readonly class CostEstimate
{
    /**
     * @param  array<string, int>  $minTokens  token count per type for the cheapest outcome
     * @param  array<string, int>  $maxTokens  token count per type for the most expensive outcome
     */
    public function __construct(
        public int $minMicroUsd,
        public int $maxMicroUsd,
        public array $minTokens,
        public array $maxTokens,
    ) {}

    /**
     * @return array{min_micro_usd: int, max_micro_usd: int, min: string, max: string, min_tokens: array<string, int>, max_tokens: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'min_micro_usd' => $this->minMicroUsd,
            'max_micro_usd' => $this->maxMicroUsd,
            'min' => Pricing::formatMicroUsd($this->minMicroUsd),
            'max' => Pricing::formatMicroUsd($this->maxMicroUsd),
            'min_tokens' => $this->minTokens,
            'max_tokens' => $this->maxTokens,
        ];
    }
}
