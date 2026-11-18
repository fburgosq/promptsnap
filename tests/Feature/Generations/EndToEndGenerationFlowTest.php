<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationSettler;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('walks the full generation lifecycle: submit -> 202 created -> processing webhook -> duplicate succeeded webhooks -> exactly one charge and exact nano amounts', function () {
    Http::preventStrayRequests();
    Queue::fake([SubmitGenerationJob::class]);
    Config::set('services.pixelforge.webhook_secret', 'whsec_e2e_secret');
    Config::set('services.pixelforge.markup_percent', 25);

    Http::fake([
        '*/pricing' => Http::response([], 200),
        '*/generations' => Http::response([
            'id' => 'gen_pf_e2e_999',
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

    $user = User::factory()->create();

    // Step 1: User submits the generation request via POST /generations
    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [
                'https://example.com/ref1.png',
            ],
        ])
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->firstOrFail();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBeNull()
        ->and($generation->provider_cost_nano)->toBeNull()
        ->and($generation->charged_nano)->toBeNull();

    // Step 2: Run the queued SubmitGenerationJob against the faked 202 response
    Queue::assertPushed(SubmitGenerationJob::class, 1);
    $job = new SubmitGenerationJob($generation);
    $job->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    $generation->refresh();
    expect($generation->provider_id)->toBe('gen_pf_e2e_999')
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submitted_at)->not->toBeNull()
        ->and($generation->rates)->toBe([
            'input_text' => '5.00',
            'input_image' => '10.00',
            'output' => '40.00',
            'reasoning' => '12.50',
        ]);

    // Step 3: Receive the generation.processing webhook
    $now = now()->utc()->getTimestamp();
    $processingBody = (string) json_encode([
        'id' => 'evt_e2e_processing_1',
        'type' => 'generation.processing',
        'data' => [
            'object' => [
                'id' => 'gen_pf_e2e_999',
                'status' => 'processing',
                'created_at' => '2026-11-18T12:00:00Z',
                'started_at' => '2026-11-18T12:00:02Z',
                'metadata' => ['generation_id' => $generation->id],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);

    $processingSig = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$processingBody}", 'whsec_e2e_secret');

    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $processingSig,
        ],
        content: $processingBody,
    )->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:02Z')
        ->and($generation->provider_cost_nano)->toBeNull()
        ->and($generation->charged_nano)->toBeNull();

    // Step 4: Receive the generation.succeeded webhook (and a duplicate delivery + a second distinct succeeded event)
    $succeededPayload = [
        'id' => 'evt_e2e_succeeded_1',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_pf_e2e_999',
                'status' => 'succeeded',
                'created_at' => '2026-11-18T12:00:00Z',
                'started_at' => '2026-11-18T12:00:02Z',
                'completed_at' => '2026-11-18T12:00:08Z',
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
                'image_url' => 'https://pixelforge.promptedit.dev/v1/images/gen_pf_e2e_999.png',
                'image_url_expires_at' => '2026-11-19T12:00:08Z',
                'metadata' => ['generation_id' => $generation->id],
            ],
        ],
    ];
    $succeededBody = (string) json_encode($succeededPayload, JSON_UNESCAPED_SLASHES);
    $succeededSig = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$succeededBody}", 'whsec_e2e_secret');

    // First delivery of evt_e2e_succeeded_1
    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $succeededSig,
        ],
        content: $succeededBody,
    )->assertNoContent();

    // Duplicate delivery of the exact same evt_e2e_succeeded_1
    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $succeededSig,
        ],
        content: $succeededBody,
    )->assertNoContent();

    // Another succeeded event with a different event_id (simulating [duplicate] keyword behavior)
    $secondSucceededPayload = $succeededPayload;
    $secondSucceededPayload['id'] = 'evt_e2e_succeeded_2';
    $secondSucceededPayload['data']['object']['usage']['reasoning'] = 7999;
    $secondSucceededBody = (string) json_encode($secondSucceededPayload, JSON_UNESCAPED_SLASHES);
    $secondSucceededSig = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$secondSucceededBody}", 'whsec_e2e_secret');

    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $secondSucceededSig,
        ],
        content: $secondSucceededBody,
    )->assertNoContent();

    // Step 5: Assert final state, webhook_events deduplication, and exact nanodollar amounts
    $generation->refresh();

    expect(Generation::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->where('event_id', 'evt_e2e_succeeded_1')->count())->toBe(1)
        ->and(WebhookEvent::query()->count())->toBe(3)
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nano)->toBe(305_510_000)
        ->and($generation->charged_nano)->toBe(381_887_500)
        ->and($generation->image_url)->toBe('https://pixelforge.promptedit.dev/v1/images/gen_pf_e2e_999.png')
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:08Z');
});
