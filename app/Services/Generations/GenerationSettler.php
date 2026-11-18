<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Credits\CreditLedgerService;
use App\Services\Pricing\CostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class GenerationSettler
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
        private readonly CreditLedgerService $creditLedger = new CreditLedgerService,
    ) {}

    /**
     * Reconcile a local Generation row against a full Pixelforge generation object.
     *
     * Runs inside a database transaction with a row-level lock (`lockForUpdate`),
     * enforces forward-only state transitions, refuses to move out of a final state,
     * and computes/records cost exactly once when transitioning into `succeeded`.
     *
     * @param  array<string, mixed>  $providerGeneration
     */
    public function applyProviderSnapshot(Generation $g, array $providerGeneration): bool
    {
        return (bool) DB::transaction(function () use ($g, $providerGeneration): bool {
            /** @var Generation|null $locked */
            $locked = Generation::query()
                ->whereKey($g->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            $changed = false;

            $providerId = is_string($providerGeneration['id'] ?? null) && $providerGeneration['id'] !== ''
                ? $providerGeneration['id']
                : null;

            if ($locked->provider_id === null && $providerId !== null) {
                $locked->provider_id = $providerId;
                $changed = true;
            }

            if ($locked->rates === null && is_array($providerGeneration['rates'] ?? null)) {
                /** @var array<string, string> $snapshotRates */
                $snapshotRates = $providerGeneration['rates'];
                $locked->rates = $snapshotRates;
                $changed = true;
            }

            $submittedAt = $this->parseUtcTimestamp($providerGeneration['created_at'] ?? $providerGeneration['submitted_at'] ?? null);
            if ($locked->submitted_at === null && $submittedAt !== null) {
                $locked->submitted_at = $submittedAt;
                $changed = true;
            }

            if ($locked->status->isFinal()) {
                if ($changed) {
                    $locked->save();
                }

                $this->syncInstance($g, $locked);

                return $changed;
            }

            $incomingStatusRaw = is_string($providerGeneration['status'] ?? null)
                ? $providerGeneration['status']
                : null;
            $incomingStatus = $incomingStatusRaw !== null
                ? GenerationStatus::tryFrom($incomingStatusRaw)
                : null;

            if ($incomingStatus === null || ! $locked->status->canTransitionTo($incomingStatus)) {
                if ($changed) {
                    $locked->save();
                }

                $this->syncInstance($g, $locked);

                return $changed;
            }

            if ($incomingStatus === GenerationStatus::Processing) {
                $locked->status = GenerationStatus::Processing;
                $locked->started_at ??= $this->parseUtcTimestamp($providerGeneration['started_at'] ?? null) ?? Carbon::now('UTC');
                $locked->save();

                $this->syncInstance($g, $locked);

                return true;
            }

            if ($incomingStatus === GenerationStatus::Succeeded) {
                /** @var array<string, string> $rates */
                $rates = is_array($providerGeneration['rates'] ?? null)
                    ? $providerGeneration['rates']
                    : ($locked->rates ?? []);

                /** @var array<string, int> $usage */
                $usage = is_array($providerGeneration['usage'] ?? null)
                    ? $providerGeneration['usage']
                    : [];

                $completedAt = $this->parseUtcTimestamp($providerGeneration['completed_at'] ?? null) ?? Carbon::now('UTC');
                $startedAt = $locked->started_at
                    ?? $this->parseUtcTimestamp($providerGeneration['started_at'] ?? null)
                    ?? $completedAt;

                $imageUrl = $this->extractImageUrl($providerGeneration);
                $expiresAt = $this->extractImageExpiry($providerGeneration, $completedAt, $imageUrl !== null);

                $providerCostNano = $this->costCalculator->actual($usage, $rates, GenerationStatus::Succeeded);
                $rawChargedNano = $this->costCalculator->charge($providerCostNano);
                $chargedNano = $this->creditLedger->debitForSucceededGeneration($locked, $rawChargedNano);

                $locked->status = GenerationStatus::Succeeded;
                $locked->rates = $rates;
                $locked->usage = $usage;
                $locked->provider_cost_nano = $providerCostNano;
                $locked->charged_nano = $chargedNano;
                $locked->image_url = $imageUrl;
                $locked->image_url_expires_at = $expiresAt;
                $locked->started_at = $startedAt;
                $locked->completed_at = $completedAt;
                $locked->error_code = null;
                $locked->error_message = null;
                $locked->save();

                $this->syncInstance($g, $locked);

                return true;
            }

            if ($incomingStatus === GenerationStatus::Failed) {
                $completedAt = $this->parseUtcTimestamp($providerGeneration['completed_at'] ?? null) ?? Carbon::now('UTC');
                [$errorCode, $errorMessage] = $this->extractError($providerGeneration);

                $locked->status = GenerationStatus::Failed;
                $locked->provider_cost_nano = 0;
                $locked->charged_nano = 0;
                $locked->error_code = $errorCode;
                $locked->error_message = $errorMessage;
                $locked->completed_at = $completedAt;
                $locked->save();

                $this->syncInstance($g, $locked);

                return true;
            }

            return $changed;
        });
    }

    /**
     * Mark a generation as failed locally (e.g. terminal API rejection or exhausted retries).
     *
     * Runs inside a database transaction with a row-level lock (`lockForUpdate`),
     * refuses to move out of a final state, and guarantees provider_cost_nano = 0 and charged_nano = 0.
     */
    public function failLocally(Generation $g, string $code, string $message): bool
    {
        return (bool) DB::transaction(function () use ($g, $code, $message): bool {
            /** @var Generation|null $locked */
            $locked = Generation::query()
                ->whereKey($g->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            if ($locked->status->isFinal()) {
                $this->syncInstance($g, $locked);

                return false;
            }

            $locked->status = GenerationStatus::Failed;
            $locked->provider_cost_nano = 0;
            $locked->charged_nano = 0;
            $locked->error_code = $code;
            $locked->error_message = $message;
            $locked->completed_at = Carbon::now('UTC');
            $locked->save();

            $this->syncInstance($g, $locked);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $providerGeneration
     */
    private function extractImageUrl(array $providerGeneration): ?string
    {
        if (is_string($providerGeneration['image_url'] ?? null) && $providerGeneration['image_url'] !== '') {
            return $providerGeneration['image_url'];
        }

        if (is_array($providerGeneration['image'] ?? null) && is_string($providerGeneration['image']['url'] ?? null)) {
            return $providerGeneration['image']['url'];
        }

        if (is_array($providerGeneration['output'] ?? null) && is_string($providerGeneration['output']['image_url'] ?? null)) {
            return $providerGeneration['output']['image_url'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $providerGeneration
     */
    private function extractImageExpiry(array $providerGeneration, Carbon $completedAt, bool $hasImage): ?Carbon
    {
        $rawExpiry = $providerGeneration['image_url_expires_at']
            ?? $providerGeneration['expires_at']
            ?? (is_array($providerGeneration['image'] ?? null) ? ($providerGeneration['image']['expires_at'] ?? null) : null);

        $parsed = $this->parseUtcTimestamp($rawExpiry);
        if ($parsed !== null) {
            return $parsed;
        }

        return $hasImage ? $completedAt->copy()->addHours(24) : null;
    }

    /**
     * @param  array<string, mixed>  $providerGeneration
     * @return array{0: string, 1: string}
     */
    private function extractError(array $providerGeneration): array
    {
        $error = $providerGeneration['error'] ?? null;

        if (is_array($error)) {
            $code = is_string($error['code'] ?? null) && $error['code'] !== ''
                ? $error['code']
                : 'generation_failed';
            $message = is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : 'Image generation failed.';

            return [$code, $message];
        }

        $code = is_string($providerGeneration['error_code'] ?? null) && $providerGeneration['error_code'] !== ''
            ? $providerGeneration['error_code']
            : 'generation_failed';

        $message = is_string($providerGeneration['error_message'] ?? null) && $providerGeneration['error_message'] !== ''
            ? $providerGeneration['error_message']
            : (is_string($error) && $error !== '' ? $error : 'Image generation failed.');

        return [$code, $message];
    }

    private function parseUtcTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->utc();
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return Carbon::createFromTimestampUTC((int) $value);
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->utc();
        }

        return null;
    }

    private function syncInstance(Generation $target, Generation $source): void
    {
        $target->setRawAttributes($source->getAttributes(), true);
        $target->syncChanges();
    }
}
