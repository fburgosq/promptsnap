<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;

it('identifies final and non-final generation statuses', function () {
    expect(GenerationStatus::Queued->isFinal())->toBeFalse()
        ->and(GenerationStatus::Processing->isFinal())->toBeFalse()
        ->and(GenerationStatus::Succeeded->isFinal())->toBeTrue()
        ->and(GenerationStatus::Failed->isFinal())->toBeTrue();
});

it('orders generation statuses monotonically by rank and enforces forward-only transitions', function () {
    expect(GenerationStatus::Queued->rank())->toBeLessThan(GenerationStatus::Processing->rank())
        ->and(GenerationStatus::Processing->rank())->toBeLessThan(GenerationStatus::Succeeded->rank())
        ->and(GenerationStatus::Succeeded->rank())->toBe(GenerationStatus::Failed->rank());

    expect(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Processing))->toBeTrue()
        ->and(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Succeeded))->toBeTrue()
        ->and(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Failed))->toBeTrue()
        ->and(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Queued))->toBeFalse()
        ->and(GenerationStatus::Succeeded->canTransitionTo(GenerationStatus::Failed))->toBeFalse()
        ->and(GenerationStatus::Failed->canTransitionTo(GenerationStatus::Succeeded))->toBeFalse();
});

it('exposes supported image sizes and qualities', function () {
    expect(ImageSize::values())->toBe(['1024x1024', '1536x1024', '1024x1536'])
        ->and(ImageQuality::values())->toBe(['low', 'medium', 'high']);
});
