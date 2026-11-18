<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Services\Pixelforge\Exceptions\ConnectionFailed;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Services\Pixelforge\Exceptions\DailyLimitExceeded;
use App\Services\Pixelforge\Exceptions\InvalidApiKey;
use App\Services\Pixelforge\Exceptions\Overloaded;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RateLimited;
use App\Services\Pixelforge\Exceptions\ValidationFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    public function __construct(
        ?string $baseUrl = null,
        ?string $apiKey = null,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $timeoutSeconds = 15,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1'), '/');
        $this->apiKey = $apiKey ?? (string) config('services.pixelforge.api_key', '');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(
            fn () => $this->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $params)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getGeneration(string $id): array
    {
        return $this->send(
            fn () => $this->request()->get("/generations/{$id}")
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getPricing(): array
    {
        return $this->send(
            fn () => $this->request(authenticated: false)->get('/pricing')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsage(string $startDate, string $endDate): array
    {
        return $this->send(
            fn () => $this->request()->get('/usage', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])
        );
    }

    protected function request(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds);

        if ($authenticated && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        return $request;
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            throw new ConnectionFailed(
                message: $exception->getMessage() ?: 'Failed to connect to Pixelforge.',
                previous: $exception,
            );
        }

        if ($response->successful()) {
            /** @var array<string, mixed> $payload */
            $payload = $response->json() ?? [];

            return $payload;
        }

        throw $this->mapErrorResponse($response);
    }

    private function mapErrorResponse(Response $response): PixelforgeException
    {
        $status = $response->status();
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var array<string, mixed> $error */
        $error = is_array($body['error'] ?? null) ? $body['error'] : $body;

        $code = is_string($error['code'] ?? null)
            ? $error['code']
            : (is_string($body['code'] ?? null) ? $body['code'] : $this->defaultErrorCode($status));

        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : (is_string($body['message'] ?? null) ? $body['message'] : "Pixelforge HTTP {$status} error.");

        $retryAfter = $this->extractRetryAfter($response, $error, $body);

        return match (true) {
            $code === 'invalid_api_key' || $status === 401 => new InvalidApiKey($message, $code, $status, $retryAfter),
            $code === 'validation_failed' || $status === 422 => new ValidationFailed(
                message: $message,
                errorCode: $code,
                status: $status,
                retryAfter: $retryAfter,
                errors: is_array($error['details'] ?? null) ? $error['details'] : (is_array($body['errors'] ?? null) ? $body['errors'] : []),
            ),
            $code === 'content_policy_violation' || $status === 400 => new ContentPolicyViolation($message, $code, $status, $retryAfter),
            $code === 'concurrency_limit_exceeded' => new ConcurrencyLimited($message, $code, $status, $retryAfter ?? 5),
            $code === 'daily_limit_exceeded' => new DailyLimitExceeded($message, $code, $status, $retryAfter),
            $code === 'rate_limit_exceeded' || $status === 429 => new RateLimited($message, $code, $status, $retryAfter),
            $code === 'overloaded' || $status === 503 => new Overloaded($message, $code, $status, $retryAfter ?? 2),
            default => new PixelforgeException($message, $code, $status, $retryAfter),
        };
    }

    /**
     * @param  array<string, mixed>  $error
     * @param  array<string, mixed>  $body
     */
    private function extractRetryAfter(Response $response, array $error, array $body): ?int
    {
        $header = $response->header('Retry-After');
        if ($header !== '' && is_numeric($header)) {
            return (int) $header;
        }

        $fromError = $error['retry_after'] ?? $body['retry_after'] ?? null;
        if (is_numeric($fromError)) {
            return (int) $fromError;
        }

        return null;
    }

    private function defaultErrorCode(int $status): string
    {
        return match ($status) {
            400 => 'content_policy_violation',
            401 => 'invalid_api_key',
            422 => 'validation_failed',
            429 => 'rate_limit_exceeded',
            503 => 'overloaded',
            default => 'unknown_error',
        };
    }
}
