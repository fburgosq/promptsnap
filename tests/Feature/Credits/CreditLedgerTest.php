<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Config::set('services.pixelforge.webhook_secret', 'whsec_credits_secret');
    Config::set('services.pixelforge.markup_percent', 25);
});

it('purchases fixed credit packs ($5, $10, $25) and updates user balance and ledger in one transaction', function () {
    $user = User::factory()->create(['balance_nano' => 0]);

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['pack_usd' => 10])
        ->assertRedirect();

    $user->refresh();

    expect($user->balance_nano)->toBe(10_000_000_000);

    $tx = CreditTransaction::query()->where('user_id', $user->id)->first();
    expect($tx)->not->toBeNull()
        ->and($tx?->type)->toBe(CreditTransactionType::Purchase)
        ->and($tx?->amount_nano)->toBe(10_000_000_000)
        ->and($tx?->generation_id)->toBeNull();
});

it('blocks generation submission when available balance is less than the generation max possible charge', function () {
    Http::preventStrayRequests();
    Http::fake(['*/pricing' => Http::response([], 200)]);
    Queue::fake([SubmitGenerationJob::class]);

    // High, 1536x1024, 400-char prompt, 2 reference images => max charge = 467,343,750 nano
    // Give the user 467,343,749 nano (1 nanodollar short)
    $user = User::factory()->create(['balance_nano' => 467_343_749]);

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [
                'https://example.com/1.png',
                'https://example.com/2.png',
            ],
        ])
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors(['credits']);

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('prevents two rapid submissions from both passing when balance can only afford one in-flight reservation', function () {
    Http::preventStrayRequests();
    Http::fake(['*/pricing' => Http::response([], 200)]);
    Queue::fake([SubmitGenerationJob::class]);

    // High, 1536x1024, 400-char prompt, 2 reference images => max charge = 467,343,750 nano each.
    // Give the user 600,000,000 nano (enough for 1 generation, not enough for 2 = 934,687,500 nano).
    $user = User::factory()->create(['balance_nano' => 600_000_000]);

    $payload = [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => [
            'https://example.com/1.png',
            'https://example.com/2.png',
        ],
    ];

    // First submission succeeds and reserves 467,343,750 nano while queued
    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), $payload)
        ->assertRedirect(route('generations.index'));

    // Second rapid submission sees available = 600,000,000 - 467,343,750 = 132,656,250 < 467,343,750 and is blocked
    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), $payload)
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors(['credits']);

    expect(Generation::query()->where('user_id', $user->id)->count())->toBe(1);
    Queue::assertPushed(SubmitGenerationJob::class, 1);
});

it('debits exactly charged_nano once on succeeded settlement and does not debit twice on duplicate webhooks', function () {
    $user = User::factory()->create([
        'balance_nano' => 1_000_000_000, // $1.00
    ]);

    $generation = Generation::factory()->for($user)->processing()->create([
        'provider_id' => 'gen_pf_credit_ok',
        'estimated_charge_nano' => 420_375_000,
        'estimated_max_charge_nano' => 467_343_750,
    ]);

    $now = now()->utc()->getTimestamp();
    $payload = [
        'id' => 'evt_credit_succ_1',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_pf_credit_ok',
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
                'image_url' => 'https://pixelforge.promptedit.dev/v1/images/gen_pf_credit_ok.png',
                'image_url_expires_at' => '2026-11-19T12:00:08Z',
                'metadata' => ['generation_id' => $generation->id],
            ],
        ],
    ];

    $rawBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$rawBody}", 'whsec_credits_secret');

    // First webhook delivery -> debits 381,887,500 nano
    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig],
        content: $rawBody,
    )->assertNoContent();

    // Duplicate delivery of same event_id
    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig],
        content: $rawBody,
    )->assertNoContent();

    // Second distinct event_id for the same succeeded generation
    $payload2 = $payload;
    $payload2['id'] = 'evt_credit_succ_2';
    $rawBody2 = (string) json_encode($payload2, JSON_UNESCAPED_SLASHES);
    $sig2 = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$rawBody2}", 'whsec_credits_secret');

    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig2],
        content: $rawBody2,
    )->assertNoContent();

    $user->refresh();
    $generation->refresh();

    // 1,000,000,000 - 381,887,500 = 618,112,500 nano
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nano)->toBe(381_887_500)
        ->and($user->balance_nano)->toBe(618_112_500);

    $ledgerEntries = CreditTransaction::query()
        ->where('generation_id', $generation->id)
        ->where('type', CreditTransactionType::GenerationCharge)
        ->get();

    expect($ledgerEntries)->toHaveCount(1)
        ->and($ledgerEntries->first()?->amount_nano)->toBe(-381_887_500);
});

it('leaves user balance untouched and creates no ledger entry when a generation fails', function () {
    $user = User::factory()->create([
        'balance_nano' => 1_000_000_000,
    ]);

    $generation = Generation::factory()->for($user)->processing()->create([
        'provider_id' => 'gen_pf_credit_fail',
        'estimated_charge_nano' => 420_375_000,
        'estimated_max_charge_nano' => 467_343_750,
    ]);

    $now = now()->utc()->getTimestamp();
    $payload = [
        'id' => 'evt_credit_fail_1',
        'type' => 'generation.failed',
        'data' => [
            'object' => [
                'id' => 'gen_pf_credit_fail',
                'status' => 'failed',
                'completed_at' => '2026-11-18T12:00:08Z',
                'error' => [
                    'code' => 'render_failed',
                    'message' => 'Failed to generate image.',
                ],
                'metadata' => ['generation_id' => $generation->id],
            ],
        ],
    ];

    $rawBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$rawBody}", 'whsec_credits_secret');

    $this->call(
        method: 'POST',
        uri: route('webhooks.pixelforge'),
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig],
        content: $rawBody,
    )->assertNoContent();

    $user->refresh();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0)
        ->and($user->balance_nano)->toBe(1_000_000_000)
        ->and(CreditTransaction::query()->where('generation_id', $generation->id)->count())->toBe(0);
});
