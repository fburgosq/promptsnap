<?php

namespace App\Services\Pricing;

final readonly class CostEstimate
{
    /**
     * @param  array{
     *     input_text: array{expected_tokens: int, min_tokens: int, max_tokens: int, expected_cost_nano: int, min_cost_nano: int, max_cost_nano: int},
     *     input_image: array{image_count: int, tokens: int, cost_nano: int},
     *     output: array{tokens: int, cost_nano: int},
     *     reasoning: array{expected_tokens: int, min_tokens: int, max_tokens: int, expected_cost_nano: int, min_cost_nano: int, max_cost_nano: int}
     * }  $breakdown
     */
    public function __construct(
        public int $expectedProviderCostNano,
        public int $minProviderCostNano,
        public int $maxProviderCostNano,
        public int $expectedChargedNano,
        public int $minChargedNano,
        public int $maxChargedNano,
        public array $breakdown = [
            'input_text' => [
                'expected_tokens' => 0,
                'min_tokens' => 0,
                'max_tokens' => 0,
                'expected_cost_nano' => 0,
                'min_cost_nano' => 0,
                'max_cost_nano' => 0,
            ],
            'input_image' => [
                'image_count' => 0,
                'tokens' => 0,
                'cost_nano' => 0,
            ],
            'output' => [
                'tokens' => 0,
                'cost_nano' => 0,
            ],
            'reasoning' => [
                'expected_tokens' => 0,
                'min_tokens' => 0,
                'max_tokens' => 0,
                'expected_cost_nano' => 0,
                'min_cost_nano' => 0,
                'max_cost_nano' => 0,
            ],
        ],
    ) {}

    /**
     * @return array{
     *     provider_cost_nano: array{expected: int, min: int, max: int},
     *     charged_nano: array{expected: int, min: int, max: int},
     *     breakdown: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'provider_cost_nano' => [
                'expected' => $this->expectedProviderCostNano,
                'min' => $this->minProviderCostNano,
                'max' => $this->maxProviderCostNano,
            ],
            'charged_nano' => [
                'expected' => $this->expectedChargedNano,
                'min' => $this->minChargedNano,
                'max' => $this->maxChargedNano,
            ],
            'breakdown' => $this->breakdown,
        ];
    }
}
