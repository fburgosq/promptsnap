<?php

use App\Services\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Services\Pixelforge\Exceptions\ConnectionFailed;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Services\Pixelforge\Exceptions\DailyLimitExceeded;
use App\Services\Pixelforge\Exceptions\InvalidApiKey;
use App\Services\Pixelforge\Exceptions\Overloaded;
use App\Services\Pixelforge\Exceptions\RateLimited;
use App\Services\Pixelforge\Exceptions\ValidationFailed;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends bearer token, Idempotency-Key header, and payload when creating a generation', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_123',
            'status' => 'queued',
        ], 202),
    ]);

    $client = new PixelforgeClient(
        baseUrl: 'https://pixelforge.promptedit.dev/v1',
        apiKey: 'pf_test_secret_key',
    );

    $response = $client->createGeneration([
        'prompt' => 'A lighthouse in a storm',
        'size' => '1024x1024',
        'quality' => 'high',
        'metadata' => ['generation_id' => '01JXYZ'],
    ], '01JXYZ');

    expect($response)->toMatchArray([
        'id' => 'gen_123',
        'status' => 'queued',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer pf_test_secret_key')
        && $request->hasHeader('Idempotency-Key', '01JXYZ')
        && $request['prompt'] === 'A lighthouse in a storm'
    );
});

it('fetches generation, pricing without auth, and usage with date query params', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_abc' => Http::response([
            'id' => 'gen_abc',
            'status' => 'succeeded',
        ], 200),
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'rates' => ['input_text' => '5.00', 'input_image' => '10.00', 'output' => '40.00', 'reasoning' => '12.50'],
        ], 200),
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'days' => [],
        ], 200),
    ]);

    $client = new PixelforgeClient(
        baseUrl: 'https://pixelforge.promptedit.dev/v1',
        apiKey: 'pf_test_secret_key',
    );

    expect($client->getGeneration('gen_abc')['id'])->toBe('gen_abc')
        ->and($client->getPricing()['rates']['output'])->toBe('40.00')
        ->and($client->getUsage('2026-11-01', '2026-11-18'))->toBe(['days' => []]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.promptedit.dev/v1/pricing'
        && ! $request->hasHeader('Authorization')
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-18'
        && $request->hasHeader('Authorization', 'Bearer pf_test_secret_key')
    );
});

it('maps Pixelforge error responses to typed exceptions carrying code, status, and retryAfter', function (
    int $status,
    array $body,
    array $headers,
    string $expectedClass,
    string $expectedCode,
    ?int $expectedRetryAfter,
) {
    Http::preventStrayRequests();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response($body, $status, $headers),
    ]);

    $client = new PixelforgeClient(
        baseUrl: 'https://pixelforge.promptedit.dev/v1',
        apiKey: 'pf_test_key',
    );

    try {
        $client->createGeneration(['prompt' => 'test'], 'idem-1');
        $this->fail("Expected {$expectedClass} to be thrown.");
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf($expectedClass)
            ->and($exception->errorCode)->toBe($expectedCode)
            ->and($exception->status)->toBe($status)
            ->and($exception->retryAfter)->toBe($expectedRetryAfter);
    }
})->with([
    '401 invalid_api_key' => [
        401,
        ['error' => ['code' => 'invalid_api_key', 'message' => 'Bad key']],
        [],
        InvalidApiKey::class,
        'invalid_api_key',
        null,
    ],
    '422 validation_failed' => [
        422,
        ['error' => ['code' => 'validation_failed', 'message' => 'Unknown field']],
        [],
        ValidationFailed::class,
        'validation_failed',
        null,
    ],
    '400 content_policy_violation' => [
        400,
        ['error' => ['code' => 'content_policy_violation', 'message' => 'Blocked prompt']],
        [],
        ContentPolicyViolation::class,
        'content_policy_violation',
        null,
    ],
    '429 rate_limit_exceeded with Retry-After header' => [
        429,
        ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']],
        ['Retry-After' => '12'],
        RateLimited::class,
        'rate_limit_exceeded',
        12,
    ],
    '429 concurrency_limit_exceeded' => [
        429,
        ['error' => ['code' => 'concurrency_limit_exceeded', 'message' => 'Max 3 in flight']],
        ['Retry-After' => '5'],
        ConcurrencyLimited::class,
        'concurrency_limit_exceeded',
        5,
    ],
    '429 daily_limit_exceeded' => [
        429,
        ['error' => ['code' => 'daily_limit_exceeded', 'message' => 'Quota reached']],
        [],
        DailyLimitExceeded::class,
        'daily_limit_exceeded',
        null,
    ],
    '503 overloaded' => [
        503,
        ['error' => ['code' => 'overloaded', 'message' => 'Try again']],
        ['Retry-After' => '2'],
        Overloaded::class,
        'overloaded',
        2,
    ],
]);

it('maps connection failures to ConnectionFailed exception', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/*' => Http::failedConnection(),
    ]);

    $client = new PixelforgeClient(
        baseUrl: 'https://pixelforge.promptedit.dev/v1',
        apiKey: 'pf_test_key',
    );

    expect(fn () => $client->getGeneration('gen_123'))
        ->toThrow(ConnectionFailed::class);
});
