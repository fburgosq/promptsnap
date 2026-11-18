<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('reconciles a stuck generation with a provider_id to succeeded with exact cost and re-dispatches stuck unsubmitted rows', function () {
    Http::preventStrayRequests();
    Queue::fake([SubmitGenerationJob::class]);

    $stuckWithProvider = Generation::factory()->processing()->create([
        'provider_id' => 'gen_pf_stuck_01',
        'updated_at' => now()->subMinutes(4),
    ]);

    $freshWithProvider = Generation::factory()->processing()->create([
        'provider_id' => 'gen_pf_fresh_02',
        'updated_at' => now()->subMinute(),
    ]);

    $stuckUnsubmitted = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
        'updated_at' => now()->subMinutes(6),
    ]);

    Http::fake([
        '*/generations/gen_pf_stuck_01' => Http::response([
            'id' => 'gen_pf_stuck_01',
            'status' => 'succeeded',
            'created_at' => '2026-11-18T12:00:00Z',
            'started_at' => '2026-11-18T12:00:02Z',
            'completed_at' => '2026-11-18T12:00:09Z',
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
            'image_url' => 'https://pixelforge.promptedit.dev/v1/images/gen_pf_stuck_01.png',
            'image_url_expires_at' => '2026-11-19T12:00:09Z',
        ], 200),
    ]);

    $this->artisan('pixelforge:reconcile')
        ->assertSuccessful();

    $reconciled = $stuckWithProvider->fresh();
    expect($reconciled?->status)->toBe(GenerationStatus::Succeeded)
        ->and($reconciled?->provider_cost_nano)->toBe(305_510_000)
        ->and($reconciled?->charged_nano)->toBe(381_887_500)
        ->and($reconciled?->image_url)->toBe('https://pixelforge.promptedit.dev/v1/images/gen_pf_stuck_01.png')
        ->and($freshWithProvider->fresh()?->status)->toBe(GenerationStatus::Processing);

    Http::assertSentCount(1);

    Queue::assertPushed(
        SubmitGenerationJob::class,
        fn (SubmitGenerationJob $job): bool => $job->generation->is($stuckUnsubmitted)
    );
});
