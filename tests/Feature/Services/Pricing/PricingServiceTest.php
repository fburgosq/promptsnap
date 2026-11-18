<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('fetches pricing from Pixelforge, caches it for 5 minutes, and exposes typed rates and token tables', function () {
    Http::preventStrayRequests();
    Cache::forget(PricingService::CACHE_KEY);

    Http::fake([
        '*/pricing' => Http::response([
            'rates' => [
                'input_text' => '6.00',
                'input_image' => '11.00',
                'output' => '42.00',
                'reasoning' => '15.00',
            ],
            'tokens' => [
                'input_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 272, '1536x1024' => 408, '1024x1536' => 408],
                    'medium' => ['1024x1024' => 1056, '1536x1024' => 1584, '1024x1536' => 1584],
                    'high' => ['1024x1024' => 4160, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 200, 'max' => 800],
                    'medium' => ['min' => 800, 'max' => 2500],
                    'high' => ['min' => 2000, 'max' => 8000],
                ],
            ],
        ], 200),
    ]);

    $service = app(PricingService::class);

    $first = $service->current();
    $second = $service->current();

    expect($first->rates->inputText)->toBe('6.00')
        ->and($first->rates->output)->toBe('42.00')
        ->and($first->inputImageTokens)->toBe(765)
        ->and($first->outputTokensFor(ImageQuality::High, ImageSize::Landscape))->toBe(6450)
        ->and($first->reasoningRangeFor(ImageQuality::High))->toBe(['min' => 2000, 'max' => 8000])
        ->and($second->rates->inputText)->toBe('6.00');

    Http::assertSentCount(1);
});

it('falls back safely without caching broken state when the pricing endpoint is unreachable', function () {
    Http::preventStrayRequests();
    Cache::forget(PricingService::CACHE_KEY);

    Http::fake([
        '*/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503),
    ]);

    $service = app(PricingService::class);
    $pricing = $service->current();

    expect($pricing->rates->inputText)->toBe('5.00')
        ->and($pricing->rates->output)->toBe('40.00')
        ->and($pricing->outputTokensFor(ImageQuality::High, ImageSize::Landscape))->toBe(6450)
        ->and(Cache::has(PricingService::CACHE_KEY))->toBeFalse();
});
