<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationSettler;
use App\Services\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Services\Pixelforge\Exceptions\ConnectionFailed;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Services\Pixelforge\Exceptions\DailyLimitExceeded;
use App\Services\Pixelforge\Exceptions\InvalidApiKey;
use App\Services\Pixelforge\Exceptions\Overloaded;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RateLimited;
use App\Services\Pixelforge\Exceptions\ValidationFailed;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubmitGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string BUSY_MESSAGE = "Pixelforge is busy, you weren't charged, please try again";

    public int $tries = 6;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly Generation $generation,
    ) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function handle(PixelforgeClient $client, GenerationSettler $settler): void
    {
        $generation = $this->generation->fresh() ?? $this->generation;

        if ($generation->status->isFinal() || $generation->provider_id !== null) {
            return;
        }

        $params = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'metadata' => [
                'generation_id' => $generation->id,
            ],
        ];

        if ($generation->reference_images !== []) {
            $params['reference_images'] = $generation->reference_images;
        }

        try {
            // Reusing the generation's ULID as the Idempotency-Key makes retries completely safe:
            // if a previous attempt reached Pixelforge before a network drop or timeout, repeating
            // the request with the same Idempotency-Key + params returns the exact same generation.
            $snapshot = $client->createGeneration($params, $generation->id);
        } catch (InvalidApiKey $exception) {
            Log::critical('Pixelforge API key was rejected during generation submission.', [
                'generation_id' => $generation->id,
                'status' => $exception->status,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]);

            $settler->failLocally(
                $generation,
                $exception->errorCode,
                'Image generation service configuration error. You were not charged.',
            );

            return;
        } catch (ContentPolicyViolation $exception) {
            $settler->failLocally(
                $generation,
                $exception->errorCode,
                'Your prompt was blocked by the content safety policy. You were not charged.',
            );

            return;
        } catch (DailyLimitExceeded $exception) {
            $settler->failLocally(
                $generation,
                $exception->errorCode,
                'Daily image generation quota has been reached. You were not charged.',
            );

            return;
        } catch (ValidationFailed $exception) {
            $settler->failLocally(
                $generation,
                $exception->errorCode,
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Invalid generation parameters. You were not charged.',
            );

            return;
        } catch (RateLimited|ConcurrencyLimited|Overloaded|ConnectionFailed $exception) {
            $this->handleTransientFailure($generation, $settler, $exception);

            return;
        } catch (PixelforgeException $exception) {
            if ($exception->status >= 500 || $exception->status === 0) {
                $this->handleTransientFailure($generation, $settler, $exception);

                return;
            }

            $settler->failLocally(
                $generation,
                $exception->errorCode,
                $exception->getMessage() !== '' ? $exception->getMessage() : self::BUSY_MESSAGE,
            );

            return;
        }

        if (! isset($snapshot['submitted_at']) && ! isset($snapshot['created_at'])) {
            $snapshot['submitted_at'] = Carbon::now('UTC')->toIso8601ZuluString();
        }

        $settler->applyProviderSnapshot($generation, $snapshot);

        if ($generation->submitted_at === null) {
            $generation->update(['submitted_at' => Carbon::now('UTC')]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $code = $exception instanceof PixelforgeException && $exception->errorCode !== ''
            ? $exception->errorCode
            : 'submission_exhausted';

        app(GenerationSettler::class)->failLocally(
            $this->generation,
            $code,
            self::BUSY_MESSAGE,
        );
    }

    private function handleTransientFailure(
        Generation $generation,
        GenerationSettler $settler,
        PixelforgeException $exception,
    ): void {
        $attempt = $this->attempts();

        if ($attempt >= $this->tries) {
            $settler->failLocally(
                $generation,
                $exception->errorCode !== '' ? $exception->errorCode : 'overloaded',
                self::BUSY_MESSAGE,
            );

            return;
        }

        $delay = $this->calculateRetryDelay($attempt, $exception->retryAfter);
        $this->release($delay);
    }

    public function calculateRetryDelay(int $attempt, ?int $retryAfter): int
    {
        $exponentialBase = (int) min(60, 2 ** max(1, $attempt));
        $jitter = random_int(0, 2);
        $backoffWithJitter = $exponentialBase + $jitter;

        return max($retryAfter ?? 0, $backoffWithJitter);
    }
}
