<?php

namespace App\Http\Resources;

use App\Services\Pricing\CostEstimate;
use App\Support\Nano;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CostEstimate $resource
 */
class CostEstimateResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $estimate = $this->resource;
        $breakdown = $estimate->breakdown;

        $textExpectedNano = $breakdown['input_text']['expected_cost_nano'];
        $textMinNano = $breakdown['input_text']['min_cost_nano'];
        $textMaxNano = $breakdown['input_text']['max_cost_nano'];

        $imageNano = $breakdown['input_image']['cost_nano'];
        $outputNano = $breakdown['output']['cost_nano'];

        $reasoningExpectedNano = $breakdown['reasoning']['expected_cost_nano'];
        $reasoningMinNano = $breakdown['reasoning']['min_cost_nano'];
        $reasoningMaxNano = $breakdown['reasoning']['max_cost_nano'];

        return [
            'expected_charge_nano' => $estimate->expectedChargedNano,
            'expected_charge_formatted' => Nano::format($estimate->expectedChargedNano),
            'min_charge_nano' => $estimate->minChargedNano,
            'min_charge_formatted' => Nano::format($estimate->minChargedNano),
            'max_charge_nano' => $estimate->maxChargedNano,
            'max_charge_formatted' => Nano::format($estimate->maxChargedNano),
            'expected_provider_cost_nano' => $estimate->expectedProviderCostNano,
            'expected_provider_cost_formatted' => Nano::format($estimate->expectedProviderCostNano),
            'max_provider_cost_nano' => $estimate->maxProviderCostNano,
            'max_provider_cost_formatted' => Nano::format($estimate->maxProviderCostNano),
            'breakdown' => [
                'input_text' => [
                    'label' => 'Prompt text',
                    'expected_tokens' => $breakdown['input_text']['expected_tokens'],
                    'min_tokens' => $breakdown['input_text']['min_tokens'],
                    'max_tokens' => $breakdown['input_text']['max_tokens'],
                    'expected_charge_nano' => $textExpectedNano,
                    'expected_charge_formatted' => Nano::format($textExpectedNano),
                    'min_charge_formatted' => Nano::format($textMinNano),
                    'max_charge_formatted' => Nano::format($textMaxNano),
                ],
                'input_image' => [
                    'label' => 'Reference images',
                    'image_count' => $breakdown['input_image']['image_count'],
                    'tokens' => $breakdown['input_image']['tokens'],
                    'charge_nano' => $imageNano,
                    'charge_formatted' => Nano::format($imageNano),
                ],
                'output' => [
                    'label' => 'Rendered image output',
                    'tokens' => $breakdown['output']['tokens'],
                    'charge_nano' => $outputNano,
                    'charge_formatted' => Nano::format($outputNano),
                ],
                'reasoning' => [
                    'label' => 'Model thinking (reasoning)',
                    'expected_tokens' => $breakdown['reasoning']['expected_tokens'],
                    'min_tokens' => $breakdown['reasoning']['min_tokens'],
                    'max_tokens' => $breakdown['reasoning']['max_tokens'],
                    'expected_charge_nano' => $reasoningExpectedNano,
                    'expected_charge_formatted' => Nano::format($reasoningExpectedNano),
                    'min_charge_formatted' => Nano::format($reasoningMinNano),
                    'max_charge_formatted' => Nano::format($reasoningMaxNano),
                ],
            ],
        ];
    }
}
