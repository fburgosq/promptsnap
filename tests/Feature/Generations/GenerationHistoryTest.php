<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects unauthenticated guests to login when visiting GET /generations', function () {
    $this->get(route('generations.index'))
        ->assertRedirect(route('login'));
});

it('authorizes viewing generations via GenerationPolicy and prevents user A from viewing user B generations', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $genA = Generation::factory()->for($userA)->create([
        'prompt' => 'User A private prompt',
        'created_at' => now()->subMinute(),
    ]);
    $genB = Generation::factory()->for($userB)->create([
        'prompt' => 'User B secret prompt',
        'created_at' => now(),
    ]);

    expect(Gate::forUser($userA)->allows('view', $genA))->toBeTrue()
        ->and(Gate::forUser($userA)->allows('view', $genB))->toBeFalse();

    $this->actingAs($userA)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $genA->id)
            ->where('generations.data.0.prompt', 'User A private prompt')
        );
});

it('orders generations newest first and paginates them', function () {
    $user = User::factory()->create();

    $older = Generation::factory()->for($user)->create([
        'prompt' => 'Older generation',
        'created_at' => now()->subMinutes(10),
    ]);

    $newer = Generation::factory()->for($user)->create([
        'prompt' => 'Newer generation',
        'created_at' => now()->subMinutes(1),
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.1.id', $older->id)
        );
});

it('distinguishes queue status copy, expired images, succeeded charges, and free failed generations in GenerationResource', function () {
    $user = User::factory()->create();

    // 1. Queued locally (no provider_id yet)
    $localQueued = Generation::factory()->for($user)->create([
        'provider_id' => null,
        'status' => GenerationStatus::Queued,
        'created_at' => now()->subSeconds(10),
    ]);

    // 2. Queued at Pixelforge (has provider_id)
    $remoteQueued = Generation::factory()->for($user)->create([
        'provider_id' => 'gen_pf_queued_1',
        'status' => GenerationStatus::Queued,
        'created_at' => now()->subSeconds(20),
    ]);

    // 3. Processing
    $processing = Generation::factory()->for($user)->processing()->create([
        'created_at' => now()->subSeconds(30),
    ]);

    // 4. Succeeded with active image
    $succeededActive = Generation::factory()->for($user)->succeeded()->create([
        'charged_nano' => 381_887_500,
        'image_url_expires_at' => now()->addHours(12),
        'created_at' => now()->subSeconds(40),
    ]);

    // 5. Succeeded with expired image
    $succeededExpired = Generation::factory()->for($user)->succeeded()->create([
        'charged_nano' => 381_887_500,
        'image_url_expires_at' => now()->subMinute(),
        'created_at' => now()->subSeconds(50),
    ]);

    // 6. Failed (Free)
    $failed = Generation::factory()->for($user)->failed()->create([
        'error_message' => 'Your prompt was blocked by the content safety policy. You were not charged.',
        'created_at' => now()->subSeconds(60),
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 6)
            // 0: localQueued
            ->where('generations.data.0.id', $localQueued->id)
            ->where('generations.data.0.status_description', '1 minute Waiting in our queue to submit to Pixelforge...')
            ->where('generations.data.0.is_final', false)
            // 1: remoteQueued
            ->where('generations.data.1.id', $remoteQueued->id)
            ->where('generations.data.1.status_description', 'Waiting in the Pixelforge render queue...')
            ->where('generations.data.1.is_final', false)
            // 2: processing
            ->where('generations.data.2.id', $processing->id)
            ->where('generations.data.2.status_description', 'Generating your image now...')
            ->where('generations.data.2.is_final', false)
            // 3: succeededActive
            ->where('generations.data.3.id', $succeededActive->id)
            ->where('generations.data.3.is_image_expired', false)
            ->where('generations.data.3.actual_charge_display', '$0.3818875')
            ->where('generations.data.3.is_final', true)
            // 4: succeededExpired
            ->where('generations.data.4.id', $succeededExpired->id)
            ->where('generations.data.4.is_image_expired', true)
            ->where('generations.data.4.is_final', true)
            // 5: failed
            ->where('generations.data.5.id', $failed->id)
            ->where('generations.data.5.actual_charge_display', 'Free')
            ->where('generations.data.5.error_message', 'Your prompt was blocked by the content safety policy. You were not charged.')
            ->where('generations.data.5.is_final', true)
        );
});
