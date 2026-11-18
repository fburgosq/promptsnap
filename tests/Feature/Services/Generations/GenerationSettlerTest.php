<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationSettler;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function providerSnapshot(string $status, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'gen_pf_001',
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
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/v1/images/gen_pf_001.png' : null,
        'image_url_expires_at' => $status === 'succeeded' ? '2026-11-19T12:00:08Z' : null,
        'error' => $status === 'failed' ? [
            'code' => 'render_timeout',
            'message' => 'GPU worker timed out while rendering.',
        ] : null,
    ], $overrides);
}

it('settles succeeded once and ignores a duplicate succeeded snapshot without recomputing cost', function () {
    $generation = Generation::factory()->processing()->create([
        'provider_id' => 'gen_pf_001',
    ]);
    $settler = app(GenerationSettler::class);

    $firstChanged = $settler->applyProviderSnapshot($generation, providerSnapshot('succeeded'));

    expect($firstChanged)->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nano)->toBe(305_510_000)
        ->and($generation->charged_nano)->toBe(381_887_500)
        ->and($generation->image_url)->toBe('https://pixelforge.promptedit.dev/v1/images/gen_pf_001.png')
        ->and($generation->image_url_expires_at?->toIso8601ZuluString())->toBe('2026-11-19T12:00:08Z')
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:08Z');

    // Attempt a second settle with wildly different usage/rates to prove cost is never recomputed.
    $secondChanged = $settler->applyProviderSnapshot($generation, providerSnapshot('succeeded', [
        'rates' => [
            'input_text' => '99.00',
            'input_image' => '99.00',
            'output' => '99.00',
            'reasoning' => '99.00',
        ],
        'usage' => [
            'input_text' => 9999,
            'input_image' => 9999,
            'output' => 9999,
            'reasoning' => 9999,
        ],
    ]));

    $fresh = $generation->fresh();

    expect($secondChanged)->toBeFalse()
        ->and($fresh?->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh?->provider_cost_nano)->toBe(305_510_000)
        ->and($fresh?->charged_nano)->toBe(381_887_500);
});

it('ignores an out-of-order processing snapshot arriving after succeeded', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_pf_001']);
    $settler = app(GenerationSettler::class);

    $settler->applyProviderSnapshot($generation, providerSnapshot('succeeded'));

    $changed = $settler->applyProviderSnapshot($generation, providerSnapshot('processing', [
        'started_at' => '2026-11-18T12:05:00Z',
    ]));

    $fresh = $generation->fresh();

    expect($changed)->toBeFalse()
        ->and($fresh?->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh?->provider_cost_nano)->toBe(305_510_000)
        ->and($fresh?->charged_nano)->toBe(381_887_500)
        ->and($fresh?->started_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:02Z');
});

it('settles failed as free and final, and ignores any subsequent transition', function () {
    $generation = Generation::factory()->processing()->create([
        'provider_id' => 'gen_pf_001',
    ]);
    $settler = app(GenerationSettler::class);

    $changed = $settler->applyProviderSnapshot($generation, providerSnapshot('failed'));

    expect($changed)->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->provider_cost_nano)->toBe(0)
        ->and($generation->charged_nano)->toBe(0)
        ->and($generation->error_code)->toBe('render_timeout')
        ->and($generation->error_message)->toBe('GPU worker timed out while rendering.')
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:08Z');

    // Attempt to transition from failed to succeeded -> must be refused
    $afterFailed = $settler->applyProviderSnapshot($generation, providerSnapshot('succeeded'));

    $fresh = $generation->fresh();

    expect($afterFailed)->toBeFalse()
        ->and($fresh?->status)->toBe(GenerationStatus::Failed)
        ->and($fresh?->provider_cost_nano)->toBe(0)
        ->and($fresh?->charged_nano)->toBe(0);
});

it('ignores a failed snapshot arriving after succeeded', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_pf_001']);
    $settler = app(GenerationSettler::class);

    $settler->applyProviderSnapshot($generation, providerSnapshot('succeeded'));

    $changed = $settler->applyProviderSnapshot($generation, providerSnapshot('failed'));

    $fresh = $generation->fresh();

    expect($changed)->toBeFalse()
        ->and($fresh?->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh?->provider_cost_nano)->toBe(305_510_000)
        ->and($fresh?->charged_nano)->toBe(381_887_500)
        ->and($fresh?->error_code)->toBeNull()
        ->and($fresh?->error_message)->toBeNull();
});

it('transitions directly from queued to succeeded when the processing event was missed and populates provider_id', function () {
    $generation = Generation::factory()->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
        'started_at' => null,
    ]);
    $settler = app(GenerationSettler::class);

    $changed = $settler->applyProviderSnapshot($generation, providerSnapshot('succeeded', [
        'id' => 'gen_pf_early_webhook',
    ]));

    expect($changed)->toBeTrue()
        ->and($generation->provider_id)->toBe('gen_pf_early_webhook')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->started_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:02Z')
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-18T12:00:08Z')
        ->and($generation->provider_cost_nano)->toBe(305_510_000)
        ->and($generation->charged_nano)->toBe(381_887_500);
});

it('prevents double-charging when two stale in-memory instances settle concurrently', function () {
    $generationA = Generation::factory()->create([
        'provider_id' => 'gen_pf_race',
        'status' => GenerationStatus::Processing,
    ]);
    // Load a second independent in-memory model instance still holding status = Processing
    $generationB = Generation::query()->findOrFail($generationA->id);

    $settler = app(GenerationSettler::class);

    $firstResult = $settler->applyProviderSnapshot($generationA, providerSnapshot('succeeded', [
        'id' => 'gen_pf_race',
    ]));

    // Second caller passes its stale in-memory $generationB (which still thinks status is Processing)
    $secondResult = $settler->applyProviderSnapshot($generationB, providerSnapshot('succeeded', [
        'id' => 'gen_pf_race',
        'usage' => [
            'input_text' => 500,
            'input_image' => 1530,
            'output' => 6450,
            'reasoning' => 8000,
        ],
    ]));

    expect($firstResult)->toBeTrue()
        ->and($secondResult)->toBeFalse()
        ->and($generationB->status)->toBe(GenerationStatus::Succeeded)
        ->and($generationB->provider_cost_nano)->toBe(305_510_000)
        ->and($generationB->charged_nano)->toBe(381_887_500)
        ->and(Generation::query()->whereKey($generationA->id)->value('charged_nano'))->toBe(381_887_500);
});
