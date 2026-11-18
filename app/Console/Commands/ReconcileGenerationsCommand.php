<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Generations\GenerationSettler;
use App\Services\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Services\Pixelforge\Exceptions\Overloaded;
use App\Services\Pixelforge\Exceptions\RateLimited;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class ReconcileGenerationsCommand extends Command
{
    protected $signature = 'pixelforge:reconcile {--batch=15 : Maximum number of records to process per category}';

    protected $description = 'Reconcile stuck in-flight Pixelforge generations and re-dispatch unsubmitted generations.';

    public function handle(PixelforgeClient $client, GenerationSettler $settler): int
    {
        $batchSize = max(1, (int) $this->option('batch'));
        $now = Carbon::now('UTC');

        $staleWithProvider = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', $now->copy()->subMinutes(2))
            ->oldest('updated_at')
            ->limit($batchSize)
            ->get();

        foreach ($staleWithProvider as $generation) {
            if ($generation->provider_id === null) {
                continue;
            }

            try {
                $snapshot = $client->getGeneration($generation->provider_id);
                $settler->applyProviderSnapshot($generation, $snapshot);
            } catch (RateLimited|ConcurrencyLimited|Overloaded) {
                // Stop polling immediately if Pixelforge signals rate or capacity pressure.
                break;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $stuckUnsubmitted = Generation::query()
            ->where('status', GenerationStatus::Queued)
            ->whereNull('provider_id')
            ->where('updated_at', '<=', $now->copy()->subMinutes(5))
            ->oldest('updated_at')
            ->limit($batchSize)
            ->get();

        foreach ($stuckUnsubmitted as $generation) {
            SubmitGenerationJob::dispatch($generation);
        }

        return self::SUCCESS;
    }
}
