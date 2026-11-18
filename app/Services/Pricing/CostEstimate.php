<?php

namespace App\Services\Pricing;

final readonly class CostEstimate
{
    public function __construct(
        public int $expectedProviderCostNano,
        public int $minProviderCostNano,
        public int $maxProviderCostNano,
        public int $expectedChargedNano,
        public int $minChargedNano,
        public int $maxChargedNano,
    ) {}

    /**
     * @return array{
     *     provider_cost_nano: array{expected: int, min: int, max: int},
     *     charged_nano: array{expected: int, min: int, max: int}
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
        ];
    }
}
