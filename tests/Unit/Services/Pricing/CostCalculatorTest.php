<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PricingData;
use App\Services\Pricing\TokenRates;
use App\Support\Nano;

it('calculates the exact max-cost estimate from the docs (high, 1536x1024, 400-char prompt, 2 reference images)', function () {
    $calculator = new CostCalculator(markupPercent: 25);
    $pricing = PricingData::fallback();
    $prompt = str_repeat('a', 400);

    $estimate = $calculator->estimate(
        prompt: $prompt,
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        referenceImageCount: 2,
        pricing: $pricing,
    );

    // 400 chars -> 100 base text tokens -> max 115 text tokens @ $5/1M = 575,000 nano
    // 2 images -> 1530 image tokens @ $10/1M = 15,300,000 nano
    // high 1536x1024 -> 6450 output tokens @ $40/1M = 258,000,000 nano
    // high max reasoning -> 8000 reasoning tokens @ $12.50/1M = 100,000,000 nano
    // Total max provider cost = 373,875,000 nano ($0.373875)
    expect($estimate->maxProviderCostNano)->toBe(373_875_000)
        ->and(Nano::format($estimate->maxProviderCostNano))->toBe('$0.373875')
        ->and($estimate->maxChargedNano)->toBe(467_343_750);

    // Expected: 100 text (500,000) + 1530 image (15,300,000) + 6450 output (258,000,000) + 5000 midpoint reasoning (62,500,000) = 336,300,000
    expect($estimate->expectedProviderCostNano)->toBe(336_300_000)
        ->and($estimate->expectedChargedNano)->toBe(420_375_000);

    // Min: 85 text (425,000) + 1530 image (15,300,000) + 6450 output (258,000,000) + 2000 min reasoning (25,000,000) = 298,725,000
    expect($estimate->minProviderCostNano)->toBe(298_725_000)
        ->and($estimate->minChargedNano)->toBe(373_406_250);
});

it('calculates exact actual provider cost and charged amount for the single-generation docs example', function () {
    $calculator = new CostCalculator(markupPercent: 25);
    $rates = new TokenRates(
        inputText: '5.00',
        inputImage: '10.00',
        output: '40.00',
        reasoning: '12.50',
    );

    $providerCost = $calculator->actual([
        'input_text' => 12,
        'input_image' => 765,
        'output' => 6450,
        'reasoning' => 3184,
    ], $rates);

    $charged = $calculator->charge($providerCost);

    expect($providerCost)->toBe(305_510_000)
        ->and($charged)->toBe(381_887_500)
        ->and($calculator->profit($providerCost, $charged))->toBe(76_377_500);
});

it('calculates exact provider cost matching the /usage 3-generations docs example', function () {
    $calculator = new CostCalculator(markupPercent: 25);
    $rates = new TokenRates(
        inputText: '5.00',
        inputImage: '10.00',
        output: '40.00',
        reasoning: '12.50',
    );

    $providerCost = $calculator->actual([
        'input_text' => 41,
        'input_image' => 0,
        'output' => 3300,
        'reasoning' => 2950,
    ], $rates);

    expect($providerCost)->toBe(169_080_000);
});

it('rounds fractional nanodollars up using ceiling integer math when applying markup', function () {
    $calculator = new CostCalculator(markupPercent: 25);

    // 1 nano * 125 / 100 = 1.25 -> ceil = 2 nano
    expect($calculator->charge(1))->toBe(2)
        // 2 nano * 125 / 100 = 2.50 -> ceil = 3 nano
        ->and($calculator->charge(2))->toBe(3)
        // 3 nano * 125 / 100 = 3.75 -> ceil = 4 nano
        ->and($calculator->charge(3))->toBe(4)
        // 4 nano * 125 / 100 = 5.00 -> exact = 5 nano
        ->and($calculator->charge(4))->toBe(5);
});

it('counts Unicode characters rather than UTF-8 bytes when estimating prompt tokens', function () {
    $calculator = new CostCalculator(markupPercent: 25);
    $pricing = PricingData::fallback();

    // 8 Unicode characters ("🎨🚀äöüéß!"), but 21 bytes in UTF-8.
    // 8 chars -> ceil(8/4) = 2 expected text tokens (min: floor(1.7) = 1, max: ceil(2.3) = 3).
    $unicodePrompt = '🎨🚀äöüéß!';
    $asciiPrompt = '12345678';

    expect(mb_strlen($unicodePrompt, 'UTF-8'))->toBe(8)
        ->and(strlen($unicodePrompt))->toBeGreaterThan(8);

    $unicodeEstimate = $calculator->estimate($unicodePrompt, ImageSize::Square, ImageQuality::Low, 0, $pricing);
    $asciiEstimate = $calculator->estimate($asciiPrompt, ImageSize::Square, ImageQuality::Low, 0, $pricing);

    expect($unicodeEstimate->expectedProviderCostNano)->toBe($asciiEstimate->expectedProviderCostNano)
        ->and($unicodeEstimate->minProviderCostNano)->toBe($asciiEstimate->minProviderCostNano)
        ->and($unicodeEstimate->maxProviderCostNano)->toBe($asciiEstimate->maxProviderCostNano);
});

it('returns zero cost for zero token usage and for failed generations', function () {
    $calculator = new CostCalculator(markupPercent: 25);
    $rates = PricingData::fallback()->rates;

    expect($calculator->actual([], $rates))->toBe(0)
        ->and($calculator->actual([
            'input_text' => 0,
            'input_image' => 0,
            'output' => 0,
            'reasoning' => 0,
        ], $rates))->toBe(0)
        ->and($calculator->charge(0))->toBe(0);

    $failedCost = $calculator->actual([
        'input_text' => 100,
        'input_image' => 765,
        'output' => 6450,
        'reasoning' => 3000,
    ], $rates, GenerationStatus::Failed);

    expect($failedCost)->toBe(0)
        ->and($calculator->charge($failedCost))->toBe(0);
});
