<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 'evt_'.fake()->unique()->lexify('????????????'),
            'type' => 'generation.succeeded',
            'provider_generation_id' => 'gen_'.fake()->lexify('????????????'),
            'payload' => ['id' => 'evt_test', 'type' => 'generation.succeeded'],
            'received_at' => now()->utc(),
        ];
    }
}
