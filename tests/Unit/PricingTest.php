<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Pricing;
use App\Support\PricingSheet;
use Brick\Math\Exception\RoundingNecessaryException;

/** Rates from the docs and the live GET /v1/pricing response. */
function docRates(): array
{
    return [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];
}

function usage(int $text, int $image, int $output, int $reasoning): array
{
    return [
        'input_text_tokens' => $text,
        'input_image_tokens' => $image,
        'output_tokens' => $output,
        'reasoning_tokens' => $reasoning,
        'total_tokens' => $text + $image + $output + $reasoning,
    ];
}

function sheet(): PricingSheet
{
    return PricingSheet::fromApi(pixelforgeFixture('pricing'));
}

describe('provider cost (exact, nano-USD)', function () {
    it('matches the worked example in the docs: $0.373875000', function () {
        // 115×5 + 1530×10 + 6450×40 + 8000×12.5 = 575 + 15300 + 258000 + 100000 micro
        expect(Pricing::providerCostNanoUsd(usage(115, 1530, 6450, 8000), docRates()))
            ->toBe(373_875_000);
    });

    it('matches the cost_usd Pixelforge reports in GET /v1/usage: $0.169080000', function () {
        // Docs usage example: 41 text, 3300 output, 2950 reasoning tokens.
        // 41×5 + 3300×40 + 2950×12.5 = 205 + 132000 + 36875 = 169080 micro
        $nano = Pricing::providerCostNanoUsd(usage(41, 0, 3300, 2950), docRates());

        expect($nano)->toBe(169_080_000)
            ->and(Pricing::formatNanoUsd($nano))->toBe('0.169080000');
    });

    it('prices the succeeded generation from the docs exactly', function () {
        // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 60 + 7650 + 258000 + 39800 = 305510 micro
        expect(Pricing::providerCostNanoUsd(usage(12, 765, 6450, 3184), docRates()))
            ->toBe(305_510_000);
    });

    it('keeps sub-micro precision: one reasoning token at $12.50/1M is $0.0000125', function () {
        expect(Pricing::providerCostNanoUsd(usage(0, 0, 0, 1), docRates()))->toBe(12_500);
    });

    it('is zero for zero usage', function () {
        expect(Pricing::providerCostNanoUsd(usage(0, 0, 0, 0), docRates()))->toBe(0);
    });

    it('uses the rates stored on the generation, not today\'s prices', function () {
        $oldRates = [...docRates(), 'output_tokens' => '20.00'];

        expect(Pricing::providerCostNanoUsd(usage(0, 0, 1000, 0), $oldRates))->toBe(20_000_000)
            ->and(Pricing::providerCostNanoUsd(usage(0, 0, 1000, 0), docRates()))->toBe(40_000_000);
    });

    it('ignores total_tokens and other extra keys', function () {
        $usage = [...usage(10, 0, 0, 0), 'total_tokens' => 999_999, 'something_new' => 5];

        expect(Pricing::providerCostNanoUsd($usage, docRates()))->toBe(50_000);
    });

    it('rejects missing, negative or non-integer token counts', function (array $usage) {
        Pricing::providerCostNanoUsd($usage, docRates());
    })->throws(InvalidArgumentException::class)->with([
        'missing type' => [['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0]],
        'negative' => [usage(-1, 0, 0, 0)],
        'float' => [[...usage(0, 0, 0, 0), 'output_tokens' => 1.0]],
        'string' => [[...usage(0, 0, 0, 0), 'output_tokens' => '100']],
    ]);

    it('rejects float, missing or negative rates', function (array $rates) {
        Pricing::providerCostNanoUsd(usage(1, 1, 1, 1), $rates);
    })->throws(InvalidArgumentException::class)->with([
        'float rate' => [[...docRates(), 'output_tokens' => 40.0]],
        'missing rate' => [array_diff_key(docRates(), ['reasoning_tokens' => true])],
        'negative rate' => [[...docRates(), 'output_tokens' => '-1.00']],
        'not a number' => [[...docRates(), 'output_tokens' => 'abc']],
        'exponent' => [[...docRates(), 'output_tokens' => '4e1']],
        'whitespace' => [[...docRates(), 'output_tokens' => ' 40.00']],
        'no leading digit' => [[...docRates(), 'output_tokens' => '.5']],
    ]);

    it('throws rather than silently rounding if a rate is finer than a nano-dollar per token', function () {
        // 1 token × $0.0001 per 1M = $0.0000000001 = 0.1 nano-dollar: not representable.
        Pricing::providerCostNanoUsd(usage(1, 0, 0, 0), [...docRates(), 'input_text_tokens' => '0.0001']);
    })->throws(RoundingNecessaryException::class);
});

describe('customer charge (cost × 1.25, half-up to micro-USD)', function () {
    it('adds 25% and rounds half-up', function (int $nano, int $expectedMicro) {
        expect(Pricing::customerChargeMicroUsd($nano))->toBe($expectedMicro);
    })->with([
        'docs worked example: 467,343.75 → 467,344' => [373_875_000, 467_344],
        'docs usage example, exact: 211,350' => [169_080_000, 211_350],
        'docs succeeded example: 381,887.5 → 381,888' => [305_510_000, 381_888],
        'exactly half rounds up: 0.5 → 1' => [400, 1],
        'just below half rounds down: 0.49875 → 0' => [399, 0],
        'one reasoning token: 15.625 → 16' => [12_500, 16],
        'zero stays zero' => [0, 0],
    ]);

    it('never charges less than the provider cost for any realistic amount', function () {
        // Cheapest possible generation: low 1024x1024, 1-char prompt, 0 reasoning = 280 output tokens.
        $cheapest = Pricing::providerCostNanoUsd(usage(0, 0, 280, 0), docRates());

        foreach ([$cheapest, $cheapest + 1, 373_875_000, 999_999_999] as $nano) {
            expect(Pricing::customerChargeMicroUsd($nano) * 1000)->toBeGreaterThan($nano);
        }
    });

    it('rejects a negative cost', function () {
        Pricing::customerChargeMicroUsd(-1);
    })->throws(InvalidArgumentException::class);
});

describe('estimate (before submit)', function () {
    it('matches the docs worked example for the maximum: $0.373875 cost → $0.467344 charged', function () {
        $estimate = Pricing::estimate(sheet(), str_repeat('a', 400), 2, ImageSize::Landscape, ImageQuality::High);

        expect($estimate->maxTokens)->toBe([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ])->and($estimate->maxMicroUsd)->toBe(467_344);
    });

    it('uses the lowest text and reasoning tokens for the minimum', function () {
        $estimate = Pricing::estimate(sheet(), str_repeat('a', 400), 2, ImageSize::Landscape, ImageQuality::High);

        // 85×5 + 1530×10 + 6450×40 + 1000×12.5 = 286,225 micro cost → ×1.25 = 357,781.25 → 357,781
        expect($estimate->minTokens)->toBe([
            'input_text_tokens' => 85,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 1000,
        ])->and($estimate->minMicroUsd)->toBe(357_781);
    });

    it('counts Unicode characters, not bytes', function () {
        // 8 characters but 16 bytes: ceil(8 / 4) = 2 tokens, so 1 … 3.
        expect(Pricing::textTokenRange(sheet(), str_repeat('é', 8)))->toBe([1, 3]);
    });

    it('rounds the text token range outwards', function (string $prompt, array $range) {
        expect(Pricing::textTokenRange(sheet(), $prompt))->toBe($range);
    })->with([
        '1 char → est 1 → 0…2' => ['a', [0, 2]],
        '5 chars → est 2 (ceil) → 1…3' => ['abcde', [1, 3]],
        '400 chars → est 100 → 85…115' => [str_repeat('a', 400), [85, 115]],
        '4000 chars → est 1000 → 850…1150' => [str_repeat('a', 4000), [850, 1150]],
    ]);

    it('always contains the real charge, for every size, quality and image count', function () {
        $sheet = sheet();
        $prompt = str_repeat('x', 237);    // est 60 → 51…69 text tokens

        foreach (ImageQuality::cases() as $quality) {
            foreach (ImageSize::cases() as $size) {
                foreach ([0, 1, 4] as $images) {
                    $estimate = Pricing::estimate($sheet, $prompt, $images, $size, $quality);
                    $reasoning = $sheet->reasoningRange($quality);

                    // Every extreme combination the docs allow for the real outcome.
                    foreach ([51, 60, 69] as $text) {
                        foreach ([$reasoning['min'], $reasoning['max']] as $thinking) {
                            $actual = Pricing::customerChargeMicroUsd(Pricing::providerCostNanoUsd(
                                usage($text, $images * 765, $sheet->outputTokensFor($quality, $size), $thinking),
                                $sheet->rates,
                            ));

                            expect($actual)
                                ->toBeGreaterThanOrEqual($estimate->minMicroUsd)
                                ->toBeLessThanOrEqual($estimate->maxMicroUsd);
                        }
                    }
                }
            }
        }
    });

    it('exposes formatted amounts for the UI', function () {
        $estimate = Pricing::estimate(sheet(), str_repeat('a', 400), 2, ImageSize::Landscape, ImageQuality::High);

        expect($estimate->toArray())->toMatchArray([
            'min_micro_usd' => 357_781,
            'max_micro_usd' => 467_344,
            'min' => '$0.357781',
            'max' => '$0.467344',
        ]);
    });
});

describe('formatting', function () {
    it('shows micro-USD exactly, trimming trailing zeros but keeping 2 decimals', function (int $micro, string $expected) {
        expect(Pricing::formatMicroUsd($micro))->toBe($expected);
    })->with([
        [467_344, '$0.467344'],
        [11_200, '$0.0112'],
        [1_500_000, '$1.50'],
        [1, '$0.000001'],
        [0, '$0.00'],
        [12_345_678, '$12.345678'],
        [-11_200, '-$0.0112'],
    ]);
});

describe('pricing sheet', function () {
    it('parses the real GET /v1/pricing response', function () {
        $sheet = sheet();

        expect($sheet->rates)->toBe(docRates())
            ->and($sheet->charactersPerTextToken)->toBe(4)
            ->and($sheet->textTokenVariance)->toBe('0.15')
            ->and($sheet->tokensPerReferenceImage)->toBe(765)
            ->and($sheet->outputTokensFor(ImageQuality::Medium, ImageSize::Portrait))->toBe(1650)
            ->and($sheet->reasoningRange(ImageQuality::Low))->toBe(['min' => 0, 'max' => 500]);
    });

    it('fails loudly when the response is missing something we rely on', function (Closure $break) {
        $data = pixelforgeFixture('pricing');
        $break($data);

        PricingSheet::fromApi($data);
    })->throws(InvalidArgumentException::class)->with([
        'no rates' => [function (array &$d) {
            unset($d['rates']);
        }],
        'float rate' => [function (array &$d) {
            $d['rates']['output_tokens'] = 40.0;
        }],
        'missing size' => [function (array &$d) {
            unset($d['tokens']['output']['high']['1024x1536']);
        }],
        'missing quality' => [function (array &$d) {
            unset($d['tokens']['reasoning']['medium']);
        }],
        'zero chars per token' => [function (array &$d) {
            $d['tokens']['characters_per_text_token'] = 0;
        }],
    ]);
});
