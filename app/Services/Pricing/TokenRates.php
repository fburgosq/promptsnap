<?php

namespace App\Services\Pricing;

final readonly class TokenRates
{
    public function __construct(
        public string $inputText,
        public string $inputImage,
        public string $output,
        public string $reasoning,
    ) {}

    /**
     * @param  array<string, mixed>  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputText: (string) ($rates['input_text'] ?? '5.00'),
            inputImage: (string) ($rates['input_image'] ?? '10.00'),
            output: (string) ($rates['output'] ?? '40.00'),
            reasoning: (string) ($rates['reasoning'] ?? '12.50'),
        );
    }

    /**
     * @return array{input_text: string, input_image: string, output: string, reasoning: string}
     */
    public function toArray(): array
    {
        return [
            'input_text' => $this->inputText,
            'input_image' => $this->inputImage,
            'output' => $this->output,
            'reasoning' => $this->reasoning,
        ];
    }
}
