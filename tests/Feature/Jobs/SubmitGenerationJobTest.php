<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Generations\GenerationSettler;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('retries after a 503 overloaded response and succeeds on 202 using the exact same Idempotency-Key', function () {
    Http::preventStrayRequests();

    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
        'submitted_at' => null,
    ]);

    Http::fake([
        '*/generations' => Http::sequence()
            ->push(['error' => ['code' => 'overloaded', 'message' => 'Try again']], 503, ['Retry-After' => '2'])
            ->push([
                'id' => 'gen_pf_after_retry',
                'status' => 'queued',
                'created_at' => '2026-11-18T12:00:00Z',
                'rates' => [
                    'input_text' => '5.00',
                    'input_image' => '10.00',
                    'output' => '40.00',
                    'reasoning' => '12.50',
                ],
            ], 202),
    ]);

    // First attempt: 503 overloaded -> releases back to queue, row remains queued with null provider_id
    $firstJob = (new SubmitGenerationJob($generation))->withFakeQueueInteractions();
    $firstJob->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    $firstJob->assertReleased();
    expect($generation->fresh()?->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()?->provider_id)->toBeNull();

    // Second attempt: 202 queued -> stores provider_id and submitted_at
    $secondJob = (new SubmitGenerationJob($generation))->withFakeQueueInteractions();
    $secondJob->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    $secondJob->assertNotReleased();

    $fresh = $generation->fresh();
    expect($fresh?->provider_id)->toBe('gen_pf_after_retry')
        ->and($fresh?->status)->toBe(GenerationStatus::Queued)
        ->and($fresh?->submitted_at)->not->toBeNull()
        ->and($fresh?->rates)->toMatchArray(['output' => '40.00']);

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    /** @var Request $req1 */
    $req1 = $recorded[0][0];
    /** @var Request $req2 */
    $req2 = $recorded[1][0];

    expect($req1->header('Idempotency-Key'))->toBe([$generation->id])
        ->and($req2->header('Idempotency-Key'))->toBe([$generation->id])
        ->and($req1['metadata']['generation_id'])->toBe($generation->id)
        ->and($req2['metadata']['generation_id'])->toBe($generation->id);
});

it('honours Retry-After when releasing on 429 rate_limit_exceeded', function () {
    Http::preventStrayRequests();

    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
    ]);

    Http::fake([
        '*/generations' => Http::response([
            'error' => [
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many requests',
            ],
        ], 429, ['Retry-After' => '30']),
    ]);

    $job = (new SubmitGenerationJob($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    // Attempt 1 backoff is 2 + jitter (2..4), so Retry-After: 30 must win.
    $job->assertReleased(delay: 30);
    expect($generation->fresh()?->status)->toBe(GenerationStatus::Queued);
});

it('marks the generation failed and free immediately without retrying on content_policy_violation', function () {
    Http::preventStrayRequests();

    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
    ]);

    Http::fake([
        '*/generations' => Http::response([
            'error' => [
                'code' => 'content_policy_violation',
                'message' => 'Prompt violates safety policy',
            ],
        ], 400),
    ]);

    $job = (new SubmitGenerationJob($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    $job->assertNotReleased();

    $fresh = $generation->fresh();
    expect($fresh?->status)->toBe(GenerationStatus::Failed)
        ->and($fresh?->provider_cost_nano)->toBe(0)
        ->and($fresh?->charged_nano)->toBe(0)
        ->and($fresh?->error_code)->toBe('content_policy_violation')
        ->and($fresh?->error_message)->toContain('content safety policy')
        ->and($fresh?->completed_at)->not->toBeNull();
});

it('marks the generation failed and free immediately without retrying on daily_limit_exceeded', function () {
    Http::preventStrayRequests();

    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
    ]);

    Http::fake([
        '*/generations' => Http::response([
            'error' => [
                'code' => 'daily_limit_exceeded',
                'message' => 'Daily limit reached',
            ],
        ], 429),
    ]);

    $job = (new SubmitGenerationJob($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    $job->assertNotReleased();

    $fresh = $generation->fresh();
    expect($fresh?->status)->toBe(GenerationStatus::Failed)
        ->and($fresh?->provider_cost_nano)->toBe(0)
        ->and($fresh?->charged_nano)->toBe(0)
        ->and($fresh?->error_code)->toBe('daily_limit_exceeded')
        ->and($fresh?->error_message)->toContain('Daily image generation quota');
});

it('logs loudly and marks failed immediately on invalid_api_key', function () {
    Http::preventStrayRequests();
    Log::spy();

    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
    ]);

    Http::fake([
        '*/generations' => Http::response([
            'error' => [
                'code' => 'invalid_api_key',
                'message' => 'Invalid bearer token',
            ],
        ], 401),
    ]);

    $job = (new SubmitGenerationJob($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    $job->assertNotReleased();

    Log::shouldHaveReceived('critical')->once();

    $fresh = $generation->fresh();
    expect($fresh?->status)->toBe(GenerationStatus::Failed)
        ->and($fresh?->provider_cost_nano)->toBe(0)
        ->and($fresh?->charged_nano)->toBe(0)
        ->and($fresh?->error_code)->toBe('invalid_api_key');
});

it('marks the generation failed and free with a friendly message when retries are exhausted', function () {
    Http::preventStrayRequests();

    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
    ]);

    Http::fake([
        '*/generations' => Http::response([
            'error' => [
                'code' => 'overloaded',
                'message' => 'Service overloaded',
            ],
        ], 503, ['Retry-After' => '2']),
    ]);

    $job = new SubmitGenerationJob($generation);

    // Simulate attempt 6 (the final allowed attempt)
    $fakeQueueJob = Mockery::mock(QueueJobContract::class);
    $fakeQueueJob->shouldReceive('attempts')->andReturn(6);
    $job->setJob($fakeQueueJob);

    $job->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    $fresh = $generation->fresh();
    expect($fresh?->status)->toBe(GenerationStatus::Failed)
        ->and($fresh?->provider_cost_nano)->toBe(0)
        ->and($fresh?->charged_nano)->toBe(0)
        ->and($fresh?->error_code)->toBe('overloaded')
        ->and($fresh?->error_message)->toBe(SubmitGenerationJob::BUSY_MESSAGE)
        ->and($fresh?->completed_at)->not->toBeNull();
});
