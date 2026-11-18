<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests away from the generate and estimate routes', function () {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
    $this->post(route('generations.store'), [])->assertRedirect(route('login'));
    $this->post(route('generations.estimate'), [])->assertRedirect(route('login'));
});

it('renders the generate page with defaults, options, and initial Nano-formatted estimate', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/pricing' => Http::response([], 200),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('defaults.size', '1024x1024')
            ->where('defaults.quality', 'medium')
            ->has('sizes', 3)
            ->has('qualities', 3)
            ->has('initialEstimate.expected_charge_formatted')
            ->has('initialEstimate.max_charge_formatted')
            ->has('initialEstimate.breakdown.input_text')
            ->has('initialEstimate.breakdown.input_image')
            ->has('initialEstimate.breakdown.output')
            ->has('initialEstimate.breakdown.reasoning')
        );
});

it('returns an exact Nano-formatted estimate and breakdown from POST /estimate', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/pricing' => Http::response([], 200),
    ]);

    $user = User::factory()->create();

    // 400-char prompt, 1536x1024, high quality, 2 reference images:
    // Max provider cost = 373,875,000 nano ($0.373875) -> max charge (+25%) = 467,343,750 nano ($0.46734375)
    // Expected provider cost = 336,300,000 nano -> expected charge (+25%) = 420,375,000 nano ($0.420375)
    $response = $this->actingAs($user)
        ->postJson(route('generations.estimate'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [
                'https://example.com/ref1.png',
                'https://example.com/ref2.png',
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('expected_charge_nano', 420_375_000)
        ->assertJsonPath('expected_charge_formatted', '$0.420375')
        ->assertJsonPath('max_charge_nano', 467_343_750)
        ->assertJsonPath('max_charge_formatted', '$0.46734375')
        ->assertJsonPath('breakdown.input_text.expected_tokens', 100)
        ->assertJsonPath('breakdown.input_image.image_count', 2)
        ->assertJsonPath('breakdown.input_image.tokens', 1530)
        ->assertJsonPath('breakdown.output.tokens', 6450)
        ->assertJsonPath('breakdown.reasoning.min_tokens', 2000)
        ->assertJsonPath('breakdown.reasoning.max_tokens', 8000);
});

it('stores a queued generation row with estimates, dispatches SubmitGenerationJob without calling Pixelforge synchronously, and redirects to history', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/pricing' => Http::response([], 200),
    ]);
    Queue::fake([SubmitGenerationJob::class]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => str_repeat('a', 400),
            'size' => ImageSize::Landscape->value,
            'quality' => ImageQuality::High->value,
            'reference_images' => [
                'https://example.com/ref1.png',
                'https://example.com/ref2.png',
            ],
        ]);

    $response->assertRedirect(route('generations.index'))
        ->assertSessionHas('status');

    $generation = Generation::query()->first();

    expect($generation)->not->toBeNull()
        ->and($generation?->user_id)->toBe($user->id)
        ->and($generation?->provider_id)->toBeNull()
        ->and($generation?->status)->toBe(GenerationStatus::Queued)
        ->and($generation?->size)->toBe(ImageSize::Landscape)
        ->and($generation?->quality)->toBe(ImageQuality::High)
        ->and($generation?->reference_images)->toBe([
            'https://example.com/ref1.png',
            'https://example.com/ref2.png',
        ])
        ->and($generation?->estimated_charge_nano)->toBe(420_375_000)
        ->and($generation?->estimated_max_charge_nano)->toBe(467_343_750);

    Queue::assertPushed(
        SubmitGenerationJob::class,
        fn (SubmitGenerationJob $job): bool => $job->generation->is($generation)
    );

    // Ensure no synchronous POST /generations request was sent
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/generations'));
});

it('applies Pixelforge defaults for size and quality when omitted', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/pricing' => Http::response([], 200),
    ]);
    Queue::fake([SubmitGenerationJob::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => 'Minimalist architectural courtyard',
        ])
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->firstOrFail();

    expect($generation->size)->toBe(ImageSize::Square)
        ->and($generation->quality)->toBe(ImageQuality::Medium)
        ->and($generation->reference_images)->toBe([]);
});

it('validates prompt length, enum values, and reference image URLs on POST /generations', function (array $payload, string $expectedErrorKey) {
    Queue::fake([SubmitGenerationJob::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), $payload)
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors([$expectedErrorKey]);

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing prompt' => [
        ['prompt' => ''],
        'prompt',
    ],
    'prompt over 4000 chars' => [
        ['prompt' => str_repeat('x', 4001)],
        'prompt',
    ],
    'invalid size enum' => [
        ['prompt' => 'Valid prompt', 'size' => '512x512'],
        'size',
    ],
    'invalid quality enum' => [
        ['prompt' => 'Valid prompt', 'quality' => 'ultra'],
        'quality',
    ],
    'more than 4 reference images' => [
        [
            'prompt' => 'Valid prompt',
            'reference_images' => [
                'https://example.com/1.png',
                'https://example.com/2.png',
                'https://example.com/3.png',
                'https://example.com/4.png',
                'https://example.com/5.png',
            ],
        ],
        'reference_images',
    ],
    'non-http(s) reference image URL' => [
        [
            'prompt' => 'Valid prompt',
            'reference_images' => ['ftp://example.com/1.png'],
        ],
        'reference_images.0',
    ],
    'malformed reference image URL' => [
        [
            'prompt' => 'Valid prompt',
            'reference_images' => ['not-a-valid-url'],
        ],
        'reference_images.0',
    ],
]);
