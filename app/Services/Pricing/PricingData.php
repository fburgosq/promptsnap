<?php

namespace App\Services\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use InvalidArgumentException;

final readonly class PricingData
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens
     */
    public function __construct(
        public TokenRates $rates,
        public int $inputImageTokens,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $defaults = self::fallback();

        /** @var array<string, mixed> $rawRates */
        $rawRates = is_array($payload['rates'] ?? null)
            ? $payload['rates']
            : (is_array($payload['rates_usd_per_1m'] ?? null) ? $payload['rates_usd_per_1m'] : []);

        $rates = $rawRates !== []
            ? TokenRates::fromArray($rawRates)
            : $defaults->rates;

        /** @var array<string, mixed> $tokens */
        $tokens = is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [];

        $inputImageTokens = is_numeric($tokens['input_image'] ?? null)
            ? (int) $tokens['input_image']
            : (is_array($tokens['input_image'] ?? null) && is_numeric($tokens['input_image']['per_image'] ?? null)
                ? (int) $tokens['input_image']['per_image']
                : $defaults->inputImageTokens);

        $outputTokens = $defaults->outputTokens;
        if (is_array($tokens['output'] ?? null)) {
            foreach ($tokens['output'] as $qualityKey => $sizes) {
                if (is_string($qualityKey) && is_array($sizes)) {
                    foreach ($sizes as $sizeKey => $count) {
                        if (is_string($sizeKey) && is_numeric($count)) {
                            $outputTokens[$qualityKey][$sizeKey] = (int) $count;
                        }
                    }
                }
            }
        }

        $reasoningTokens = $defaults->reasoningTokens;
        if (is_array($tokens['reasoning'] ?? null)) {
            foreach ($tokens['reasoning'] as $qualityKey => $range) {
                if (! is_string($qualityKey) || ! is_array($range)) {
                    continue;
                }

                if (isset($range['min'], $range['max']) && is_numeric($range['min']) && is_numeric($range['max'])) {
                    $reasoningTokens[$qualityKey] = [
                        'min' => (int) $range['min'],
                        'max' => (int) $range['max'],
                    ];
                } elseif (isset($range[0], $range[1]) && is_numeric($range[0]) && is_numeric($range[1])) {
                    $reasoningTokens[$qualityKey] = [
                        'min' => (int) $range[0],
                        'max' => (int) $range[1],
                    ];
                }
            }
        }

        return new self(
            rates: $rates,
            inputImageTokens: $inputImageTokens,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
        );
    }

    public static function fallback(): self
    {
        return new self(
            rates: new TokenRates(
                inputText: '5.00',
                inputImage: '10.00',
                output: '40.00',
                reasoning: '12.50',
            ),
            inputImageTokens: 765,
            outputTokens: [
                ImageQuality::Low->value => [
                    ImageSize::Square->value => 272,
                    ImageSize::Landscape->value => 408,
                    ImageSize::Portrait->value => 408,
                ],
                ImageQuality::Medium->value => [
                    ImageSize::Square->value => 1056,
                    ImageSize::Landscape->value => 1584,
                    ImageSize::Portrait->value => 1584,
                ],
                ImageQuality::High->value => [
                    ImageSize::Square->value => 4160,
                    ImageSize::Landscape->value => 6450,
                    ImageSize::Portrait->value => 6450,
                ],
            ],
            reasoningTokens: [
                ImageQuality::Low->value => ['min' => 200, 'max' => 800],
                ImageQuality::Medium->value => ['min' => 800, 'max' => 2500],
                ImageQuality::High->value => ['min' => 2000, 'max' => 8000],
            ],
        );
    }

    public function outputTokensFor(ImageQuality|string $quality, ImageSize|string $size): int
    {
        $qualityKey = $quality instanceof ImageQuality ? $quality->value : $quality;
        $sizeKey = $size instanceof ImageSize ? $size->value : $size;

        $tokens = $this->outputTokens[$qualityKey][$sizeKey] ?? null;
        if ($tokens === null) {
            throw new InvalidArgumentException("Unknown output token entry for quality [{$qualityKey}] and size [{$sizeKey}].");
        }

        return $tokens;
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRangeFor(ImageQuality|string $quality): array
    {
        $qualityKey = $quality instanceof ImageQuality ? $quality->value : $quality;

        $range = $this->reasoningTokens[$qualityKey] ?? null;
        if ($range === null) {
            throw new InvalidArgumentException("Unknown reasoning token range for quality [{$qualityKey}].");
        }

        return $range;
    }

    /**
     * @return array{
     *     rates: array{input_text: string, input_image: string, output: string, reasoning: string},
     *     tokens: array{
     *         input_image: int,
     *         output: array<string, array<string, int>>,
     *         reasoning: array<string, array{min: int, max: int}>
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates->toArray(),
            'tokens' => [
                'input_image' => $this->inputImageTokens,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }
}
