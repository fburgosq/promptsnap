<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_id' => null,
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(8),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'estimated_charge_nano' => 75_000_000,
            'estimated_max_charge_nano' => 95_000_000,
            'rates' => null,
            'usage' => null,
            'provider_cost_nano' => null,
            'charged_nano' => null,
            'image_url' => null,
            'image_url_expires_at' => null,
            'error_code' => null,
            'error_message' => null,
            'submitted_at' => now()->utc(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'provider_id' => 'gen_'.fake()->unique()->lexify('????????????'),
            'status' => GenerationStatus::Processing,
            'started_at' => now()->utc(),
        ]);
    }

    public function succeeded(): static
    {
        $completedAt = now()->utc();

        return $this->state(fn () => [
            'provider_id' => 'gen_'.fake()->unique()->lexify('????????????'),
            'status' => GenerationStatus::Succeeded,
            'rates' => [
                'input_text' => '5.00',
                'input_image' => '10.00',
                'output' => '40.00',
                'reasoning' => '12.50',
            ],
            'usage' => [
                'input_text' => 12,
                'input_image' => 765,
                'output' => 6450,
                'reasoning' => 3184,
            ],
            'provider_cost_nano' => 305_510_000,
            'charged_nano' => 381_887_500,
            'image_url' => 'https://pixelforge.promptedit.dev/images/sample.png',
            'image_url_expires_at' => $completedAt->copy()->addHours(24),
            'started_at' => $completedAt->copy()->subSeconds(5),
            'completed_at' => $completedAt,
        ]);
    }

    public function failed(): static
    {
        $completedAt = now()->utc();

        return $this->state(fn () => [
            'provider_id' => 'gen_'.fake()->unique()->lexify('????????????'),
            'status' => GenerationStatus::Failed,
            'provider_cost_nano' => 0,
            'charged_nano' => 0,
            'error_code' => 'generation_failed',
            'error_message' => 'Simulated failure.',
            'started_at' => $completedAt->copy()->subSeconds(2),
            'completed_at' => $completedAt,
        ]);
    }
}
