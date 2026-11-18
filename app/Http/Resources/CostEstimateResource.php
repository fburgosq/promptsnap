<?php

namespace App\Http\Resources;

use App\Services\Pricing\CostCalculator;
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

    public function __construct(
        CostEstimate $resource,
        private readonly CostCalculator $calculator = new CostCalculator,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $estimate = $this->resource;
        $breakdown = $estimate->breakdown;

        $textExpectedCharged = $this->calculator->charge($breakdown['input_text']['expected_cost_nano']);
        $textMinCharged = $this->calculator->charge($breakdown['input_text']['min_cost_nano']);
        $textMaxCharged = $this->calculator->charge($breakdown['input_text']['max_cost_nano']);

        $imageCharged = $this->calculator->charge($breakdown['input_image']['cost_nano']);
        $outputCharged = $this->calculator->charge($breakdown['output']['cost_nano']);

        $reasoningExpectedCharged = $this->calculator->charge($breakdown['reasoning']['expected_cost_nano']);
        $reasoningMinCharged = $this->calculator->charge($breakdown['reasoning']['min_cost_nano']);
        $reasoningMaxCharged = $this->calculator->charge($breakdown['reasoning']['max_cost_nano']);

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
                    'expected_charge_nano' => $textExpectedCharged,
                    'expected_charge_formatted' => Nano::format($textExpectedCharged),
                    'min_charge_formatted' => Nano::format($textMinCharged),
                    'max_charge_formatted' => Nano::format($textMaxCharged),
                ],
                'input_image' => [
                    'label' => 'Reference images',
                    'image_count' => $breakdown['input_image']['image_count'],
                    'tokens' => $breakdown['input_image']['tokens'],
                    'charge_nano' => $imageCharged,
                    'charge_formatted' => Nano::format($imageCharged),
                ],
                'output' => [
                    'label' => 'Rendered image output',
                    'tokens' => $breakdown['output']['tokens'],
                    'charge_nano' => $outputCharged,
                    'charge_formatted' => Nano::format($outputCharged),
                ],
                'reasoning' => [
                    'label' => 'Model thinking (reasoning)',
                    'expected_tokens' => $breakdown['reasoning']['expected_tokens'],
                    'min_tokens' => $breakdown['reasoning']['min_tokens'],
                    'max_tokens' => $breakdown['reasoning']['max_tokens'],
                    'expected_charge_nano' => $reasoningExpectedCharged,
                    'expected_charge_formatted' => Nano::format($reasoningExpectedCharged),
                    'min_charge_formatted' => Nano::format($reasoningMinCharged),
                    'max_charge_formatted' => Nano::format($reasoningMaxCharged),
                ],
            ],
        ];
    }
}
