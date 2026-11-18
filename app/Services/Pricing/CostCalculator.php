<?php

namespace App\Services\Pricing;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Nano;
use InvalidArgumentException;

final class CostCalculator
{
    public function __construct(
        private readonly ?int $markupPercent = null,
    ) {}

    /**
     * Estimate expected, minimum, and maximum provider & charged costs in nanodollars.
     */
    public function estimate(
        string $prompt,
        ImageSize|string $size,
        ImageQuality|string $quality,
        int $referenceImageCount,
        PricingData $pricing,
    ): CostEstimate {
        if ($referenceImageCount < 0) {
            throw new InvalidArgumentException("Reference image count cannot be negative: [{$referenceImageCount}].");
        }

        $charCount = mb_strlen($prompt, 'UTF-8');
        $expectedTextTokens = Nano::ceilDiv($charCount, 4);
        $minTextTokens = intdiv($expectedTextTokens * 85, 100);
        $maxTextTokens = Nano::ceilDiv($expectedTextTokens * 115, 100);

        $imageTokens = $pricing->inputImageTokens * $referenceImageCount;
        $outputTokens = $pricing->outputTokensFor($quality, $size);

        $reasoningRange = $pricing->reasoningRangeFor($quality);
        $minReasoningTokens = $reasoningRange['min'];
        $maxReasoningTokens = $reasoningRange['max'];
        $expectedReasoningTokens = intdiv($minReasoningTokens + $maxReasoningTokens, 2);

        $rates = $pricing->rates;

        $expectedTextCostNano = Nano::tokenCost($expectedTextTokens, $rates->inputText);
        $minTextCostNano = Nano::tokenCost($minTextTokens, $rates->inputText);
        $maxTextCostNano = Nano::tokenCost($maxTextTokens, $rates->inputText);

        $imageCostNano = Nano::tokenCost($imageTokens, $rates->inputImage);
        $outputCostNano = Nano::tokenCost($outputTokens, $rates->output);

        $expectedReasoningCostNano = Nano::tokenCost($expectedReasoningTokens, $rates->reasoning);
        $minReasoningCostNano = Nano::tokenCost($minReasoningTokens, $rates->reasoning);
        $maxReasoningCostNano = Nano::tokenCost($maxReasoningTokens, $rates->reasoning);

        $expectedProvider = $expectedTextCostNano + $imageCostNano + $outputCostNano + $expectedReasoningCostNano;
        $minProvider = $minTextCostNano + $imageCostNano + $outputCostNano + $minReasoningCostNano;
        $maxProvider = $maxTextCostNano + $imageCostNano + $outputCostNano + $maxReasoningCostNano;

        return new CostEstimate(
            expectedProviderCostNano: $expectedProvider,
            minProviderCostNano: $minProvider,
            maxProviderCostNano: $maxProvider,
            expectedChargedNano: $this->charge($expectedProvider),
            minChargedNano: $this->charge($minProvider),
            maxChargedNano: $this->charge($maxProvider),
            breakdown: [
                'input_text' => [
                    'expected_tokens' => $expectedTextTokens,
                    'min_tokens' => $minTextTokens,
                    'max_tokens' => $maxTextTokens,
                    'expected_cost_nano' => $expectedTextCostNano,
                    'min_cost_nano' => $minTextCostNano,
                    'max_cost_nano' => $maxTextCostNano,
                ],
                'input_image' => [
                    'image_count' => $referenceImageCount,
                    'tokens' => $imageTokens,
                    'cost_nano' => $imageCostNano,
                ],
                'output' => [
                    'tokens' => $outputTokens,
                    'cost_nano' => $outputCostNano,
                ],
                'reasoning' => [
                    'expected_tokens' => $expectedReasoningTokens,
                    'min_tokens' => $minReasoningTokens,
                    'max_tokens' => $maxReasoningTokens,
                    'expected_cost_nano' => $expectedReasoningCostNano,
                    'min_cost_nano' => $minReasoningCostNano,
                    'max_cost_nano' => $maxReasoningCostNano,
                ],
            ],
        );
    }

    /**
     * Compute exact provider cost in nanodollars from token usage and the generation's own rates snapshot.
     *
     * Failed generations always incur 0 cost.
     *
     * @param  array<string, int|null>  $usage
     * @param  TokenRates|array<string, string>  $rates
     */
    public function actual(
        array $usage,
        TokenRates|array $rates,
        ?GenerationStatus $status = null,
    ): int {
        if ($status === GenerationStatus::Failed) {
            return 0;
        }

        if ($usage === []) {
            return 0;
        }

        $tokenRates = $rates instanceof TokenRates ? $rates : TokenRates::fromArray($rates);

        $inputTextTokens = (int) ($usage['input_text'] ?? $usage['input_text_tokens'] ?? 0);
        $inputImageTokens = (int) ($usage['input_image'] ?? $usage['input_image_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output'] ?? $usage['output_tokens'] ?? 0);
        $reasoningTokens = (int) ($usage['reasoning'] ?? $usage['reasoning_tokens'] ?? 0);

        return Nano::tokenCost($inputTextTokens, $tokenRates->inputText)
            + Nano::tokenCost($inputImageTokens, $tokenRates->inputImage)
            + Nano::tokenCost($outputTokens, $tokenRates->output)
            + Nano::tokenCost($reasoningTokens, $tokenRates->reasoning);
    }

    /**
     * Apply the single markup + ceiling rounding rule in integer math:
     * charged = ceil(provider_cost_nano * (100 + markup_percent) / 100).
     */
    public function charge(int $providerCostNano, ?int $markupPercent = null): int
    {
        if ($providerCostNano < 0) {
            throw new InvalidArgumentException("Provider cost cannot be negative: [{$providerCostNano}].");
        }

        if ($providerCostNano === 0) {
            return 0;
        }

        $markup = $markupPercent ?? $this->markupPercent ?? (int) config('services.pixelforge.markup_percent', 25);

        if ($markup < 0) {
            throw new InvalidArgumentException("Markup percent cannot be negative: [{$markup}].");
        }

        return Nano::ceilDiv($providerCostNano * (100 + $markup), 100);
    }

    /**
     * Compute profit in nanodollars: charged - provider cost.
     */
    public function profit(int $providerCostNano, ?int $chargedNano = null): int
    {
        $charged = $chargedNano ?? $this->charge($providerCostNano);

        return $charged - $providerCostNano;
    }
}
