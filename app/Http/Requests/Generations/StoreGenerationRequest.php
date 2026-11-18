<?php

namespace App\Http\Requests\Generations;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
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
            'size' => $this->input('size') ?: ImageSize::Square->value,
            'quality' => $this->input('quality') ?: ImageQuality::Medium->value,
            'reference_images' => $filteredImages,
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::enum(ImageSize::class)],
            'quality' => ['required', 'string', Rule::enum(ImageQuality::class)],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Please enter a prompt describing the image you want to generate.',
            'prompt.max' => 'Prompts cannot exceed 4,000 characters.',
            'reference_images.max' => 'You can attach at most 4 reference image URLs.',
            'reference_images.*.url' => 'Each reference image must be a valid HTTP or HTTPS URL.',
        ];
    }
}
