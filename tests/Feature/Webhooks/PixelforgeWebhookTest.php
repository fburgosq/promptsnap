<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>|string  $payload
 */
function postWebhook(
    array|string $payload,
    ?string $secret = 'whsec_test_secret',
    ?int $timestamp = null,
    ?string $overrideSignature = null,
): TestResponse {
    $rawBody = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $ts = $timestamp ?? now()->utc()->getTimestamp();

    $header = $overrideSignature ?? (
        $secret !== null
            ? "t={$ts},v1=".hash_hmac('sha256', "{$ts}.{$rawBody}", $secret)
            : ''
    );

    return test()->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $header,
        ],
        content: $rawBody,
    );
}

/**
 * @param  array<string, mixed>  $objectOverrides
 * @return array<string, mixed>
 */
function webhookPayload(string $eventId, string $type, array $objectOverrides = []): array
{
    $status = str_starts_with($type, 'generation.')
        ? substr($type, strlen('generation.'))
        : 'queued';

    return [
        'id' => $eventId,
        'type' => $type,
        'data' => [
            'object' => array_replace_recursive([
                'id' => 'gen_pf_100',
                'status' => $status,
                'created_at' => '2026-11-18T12:00:00Z',
                'started_at' => $status !== 'queued' ? '2026-11-18T12:00:02Z' : null,
                'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-18T12:00:08Z' : null,
                'rates' => [
                    'input_text' => '5.00',
                    'input_image' => '10.00',
                    'output' => '40.00',
                    'reasoning' => '12.50',
                ],
                'usage' => $status === 'succeeded' ? [
                    'input_text' => 12,
                    'input_image' => 765,
                    'output' => 6450,
                    'reasoning' => 3184,
                ] : null,
                'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/v1/images/gen_pf_100.png' : null,
                'image_url_expires_at' => $status === 'succeeded' ? '2026-11-19T12:00:08Z' : null,
                'error' => $status === 'failed' ? [
                    'code' => 'generation_failed',
                    'message' => 'Failed to render image.',
                ] : null,
                'metadata' => [],
            ], $objectOverrides),
        ],
    ];
}

beforeEach(function () {
    Config::set('services.pixelforge.webhook_secret', 'whsec_test_secret');
    Config::set('services.pixelforge.webhook_tolerance_seconds', 300);
});

it('rejects requests with an invalid signature, wrong secret, stale timestamp (>300s), or tampered body without logging the raw body', function () {
    Log::spy();

    $payload = webhookPayload('evt_invalid_1', 'generation.succeeded');
    $rawJson = (string) json_encode($payload);
    $now = now()->utc()->getTimestamp();

    // 1. Invalid signature string
    postWebhook($rawJson, overrideSignature: "t={$now},v1=".str_repeat('a', 64))
        ->assertStatus(400);

    // 2. Wrong secret
    postWebhook($rawJson, secret: 'whsec_wrong_secret', timestamp: $now)
        ->assertStatus(400);

    // 3. Stale timestamp (> 300s)
    postWebhook($rawJson, secret: 'whsec_test_secret', timestamp: $now - 301)
        ->assertStatus(400);

    // 4. Tampered body (signed for original JSON, sent modified JSON)
    $validSigForOriginal = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$rawJson}", 'whsec_test_secret');
    $tamperedJson = str_replace('evt_invalid_1', 'evt_tampered', $rawJson);
    postWebhook($tamperedJson, overrideSignature: $validSigForOriginal)
        ->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->times(4)
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'invalid or stale signature')
            && ! in_array($rawJson, $context, true)
            && ! isset($context['body'])
        );
});

it('records ping events and responds 204', function () {
    $payload = [
        'id' => 'evt_ping_01',
        'type' => 'ping',
        'data' => ['object' => []],
    ];

    postWebhook($payload)->assertNoContent();

    expect(WebhookEvent::query()->where('event_id', 'evt_ping_01')->exists())->toBeTrue();
});

it('settles a valid succeeded event and deduplicates the exact same event_id on replay', function () {
    $generation = Generation::factory()->processing()->create([
        'provider_id' => 'gen_pf_100',
    ]);

    $payload = webhookPayload('evt_dup_100', 'generation.succeeded', [
        'id' => 'gen_pf_100',
        'metadata' => ['generation_id' => $generation->id],
    ]);

    // First delivery
    postWebhook($payload)->assertNoContent();

    // Second delivery of the exact same event_id (even if payload usage was mutated in transit)
    $mutatedReplay = webhookPayload('evt_dup_100', 'generation.succeeded', [
        'id' => 'gen_pf_100',
        'usage' => ['input_text' => 9999, 'input_image' => 9999, 'output' => 9999, 'reasoning' => 9999],
    ]);
    postWebhook($mutatedReplay)->assertNoContent();

    $fresh = $generation->fresh();

    expect(WebhookEvent::query()->where('event_id', 'evt_dup_100')->count())->toBe(1)
        ->and($fresh?->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh?->provider_cost_nano)->toBe(305_510_000)
        ->and($fresh?->charged_nano)->toBe(381_887_500);
});

it('keeps the generation succeeded when events arrive out of order (succeeded first, then processing)', function () {
    $generation = Generation::factory()->create([
        'provider_id' => 'gen_pf_ooo',
        'status' => GenerationStatus::Queued,
    ]);

    // 1. Succeeded arrives first
    postWebhook(webhookPayload('evt_ooo_succeeded', 'generation.succeeded', [
        'id' => 'gen_pf_ooo',
    ]))->assertNoContent();

    // 2. Late processing event arrives with a different event_id
    postWebhook(webhookPayload('evt_ooo_processing', 'generation.processing', [
        'id' => 'gen_pf_ooo',
    ]))->assertNoContent();

    $fresh = $generation->fresh();

    expect(WebhookEvent::query()->count())->toBe(2)
        ->and($fresh?->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh?->provider_cost_nano)->toBe(305_510_000)
        ->and($fresh?->charged_nano)->toBe(381_887_500);
});

it('processes two distinct events for the same generation back-to-back (processing then succeeded)', function () {
    $generation = Generation::factory()->create([
        'provider_id' => 'gen_pf_seq',
        'status' => GenerationStatus::Queued,
        'started_at' => null,
    ]);

    postWebhook(webhookPayload('evt_seq_1', 'generation.processing', [
        'id' => 'gen_pf_seq',
        'started_at' => '2026-11-18T12:00:02Z',
    ]))->assertNoContent();

    expect($generation->fresh()?->status)->toBe(GenerationStatus::Processing)
        ->and($generation->fresh()?->started_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:02Z');

    postWebhook(webhookPayload('evt_seq_2', 'generation.succeeded', [
        'id' => 'gen_pf_seq',
    ]))->assertNoContent();

    $fresh = $generation->fresh();

    expect(WebhookEvent::query()->count())->toBe(2)
        ->and($fresh?->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh?->provider_cost_nano)->toBe(305_510_000)
        ->and($fresh?->charged_nano)->toBe(381_887_500);
});

it('matches a webhook via metadata.generation_id when local provider_id is still null and stores provider_id', function () {
    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
    ]);

    postWebhook(webhookPayload('evt_early_1', 'generation.processing', [
        'id' => 'gen_pf_fast_webhook',
        'metadata' => [
            'generation_id' => $generation->id,
        ],
    ]))->assertNoContent();

    $fresh = $generation->fresh();

    expect($fresh?->provider_id)->toBe('gen_pf_fast_webhook')
        ->and($fresh?->status)->toBe(GenerationStatus::Processing);
});

it('settles a generation.failed webhook with zero provider cost and zero charge', function () {
    $generation = Generation::factory()->processing()->create([
        'provider_id' => 'gen_pf_fail_wh',
    ]);

    postWebhook(webhookPayload('evt_fail_1', 'generation.failed', [
        'id' => 'gen_pf_fail_wh',
        'error' => [
            'code' => 'nsfw_detected',
            'message' => 'Generated output did not pass safety filters.',
        ],
    ]))->assertNoContent();

    $fresh = $generation->fresh();

    expect($fresh?->status)->toBe(GenerationStatus::Failed)
        ->and($fresh?->provider_cost_nano)->toBe(0)
        ->and($fresh?->charged_nano)->toBe(0)
        ->and($fresh?->error_code)->toBe('nsfw_detected')
        ->and($fresh?->error_message)->toBe('Generated output did not pass safety filters.');
});

it('logs a warning and still returns 204 when a webhook references an unknown generation', function () {
    Log::spy();

    postWebhook(webhookPayload('evt_unknown_gen', 'generation.succeeded', [
        'id' => 'gen_pf_does_not_exist',
        'metadata' => ['generation_id' => '01JNONEXISTENTULID00000000'],
    ]))->assertNoContent();

    expect(WebhookEvent::query()->where('event_id', 'evt_unknown_gen')->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'unknown generation'));
});
