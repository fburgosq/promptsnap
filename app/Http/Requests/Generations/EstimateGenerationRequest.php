<?php

namespace App\Http\Requests\Generations;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $rawImages = $this->input('reference_images');
        $filteredImages = is_array($rawImages)
            ? array_values(array_filter(
                $rawImages,
                fn (mixed $item): bool => is_string($item) && trim($item) !== '',
            ))
            : [];

        $this->merge([
            'prompt' => is_string($this->input('prompt')) ? $this->input('prompt') : '',
            'size' => $this->input('size') ?: ImageSize::Square->value,
            'quality' => $this->input('quality') ?: ImageQuality::Medium->value,
            'reference_images' => $filteredImages,
            'reference_image_count' => $this->has('reference_image_count')
                ? (int) $this->input('reference_image_count')
                : count($filteredImages),
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['present', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::enum(ImageSize::class)],
            'quality' => ['required', 'string', Rule::enum(ImageQuality::class)],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_image_count' => ['required', 'integer', 'min:0', 'max:4'],
        ];
    }
}
