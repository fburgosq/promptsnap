<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Nano;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckPixelforgeUsageCommand extends Command
{
    protected $signature = 'pixelforge:usage-check
        {--from= : Start date in YYYY-MM-DD (UTC), defaults to 7 days ago}
        {--to= : End date in YYYY-MM-DD (UTC), defaults to today}';

    protected $description = 'Compare Pixelforge GET /v1/usage daily billing totals against local succeeded generation records.';

    public function handle(PixelforgeClient $client): int
    {
        $fromOption = $this->option('from');
        $toOption = $this->option('to');

        $startDate = is_string($fromOption) && $fromOption !== ''
            ? $fromOption
            : Carbon::now('UTC')->subDays(7)->toDateString();

        $endDate = is_string($toOption) && $toOption !== ''
            ? $toOption
            : Carbon::now('UTC')->toDateString();

        $startUtc = Carbon::createFromFormat('Y-m-d', $startDate, 'UTC')->startOfDay();
        $endUtc = Carbon::createFromFormat('Y-m-d', $endDate, 'UTC')->endOfDay();

        $payload = $client->getUsage($startDate, $endDate);
        $providerDays = $this->indexProviderDays($payload);
        $localDays = $this->aggregateLocalDays($startUtc, $endUtc);

        $allDates = [];
        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            $allDates[$date->format('Y-m-d')] = true;
        }
        foreach (array_keys($providerDays) as $dateKey) {
            $allDates[$dateKey] = true;
        }
        foreach (array_keys($localDays) as $dateKey) {
            $allDates[$dateKey] = true;
        }

        ksort($allDates);

        $mismatches = 0;

        foreach (array_keys($allDates) as $dateKey) {
            $remote = $providerDays[$dateKey] ?? $this->emptyBucket();
            $local = $localDays[$dateKey] ?? $this->emptyBucket();

            if ($remote['generations'] === 0 && $local['generations'] === 0 && $remote['cost_nano'] === 0 && $local['cost_nano'] === 0) {
                continue;
            }

            $diffs = [];

            if ($remote['cost_nano'] !== $local['cost_nano']) {
                $diffs[] = sprintf(
                    'cost_usd mismatch (provider: %s [%d nano] vs local: %s [%d nano])',
                    Nano::format($remote['cost_nano']),
                    $remote['cost_nano'],
                    Nano::format($local['cost_nano']),
                    $local['cost_nano'],
                );
            }

            if ($remote['generations'] !== $local['generations']) {
                $diffs[] = sprintf(
                    'generations mismatch (provider: %d vs local: %d)',
                    $remote['generations'],
                    $local['generations'],
                );
            }

            foreach (['input_text', 'input_image', 'output', 'reasoning'] as $tokenType) {
                if ($remote['tokens'][$tokenType] !== $local['tokens'][$tokenType]) {
                    $diffs[] = sprintf(
                        '%s tokens mismatch (provider: %d vs local: %d)',
                        $tokenType,
                        $remote['tokens'][$tokenType],
                        $local['tokens'][$tokenType],
                    );
                }
            }

            if ($diffs === []) {
                $this->info(sprintf(
                    '[%s] OK — %d generations, provider cost %s',
                    $dateKey,
                    $local['generations'],
                    Nano::format($local['cost_nano']),
                ));

                continue;
            }

            $mismatches++;
            $this->error(sprintf('[%s] MISMATCH: %s', $dateKey, implode('; ', $diffs)));
        }

        if ($mismatches > 0) {
            $this->error("Usage reconciliation found {$mismatches} day(s) with mismatches.");

            return self::FAILURE;
        }

        $this->info('Usage reconciliation passed with zero mismatches.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{
     *     generations: int,
     *     cost_nano: int,
     *     tokens: array{input_text: int, input_image: int, output: int, reasoning: int}
     * }>
     */
    private function indexProviderDays(array $payload): array
    {
        $rawList = is_array($payload['days'] ?? null)
            ? $payload['days']
            : (is_array($payload['data'] ?? null) ? $payload['data'] : []);

        $indexed = [];

        foreach ($rawList as $entry) {
            if (! is_array($entry) || ! is_string($entry['date'] ?? null)) {
                continue;
            }

            $date = $entry['date'];
            $costRaw = (string) ($entry['cost_usd'] ?? $entry['total_cost_usd'] ?? '0');
            $costNano = Nano::fromDecimalString($costRaw);

            $generations = (int) ($entry['generations'] ?? $entry['generations_count'] ?? $entry['count'] ?? 0);

            /** @var array<string, mixed> $tokens */
            $tokens = is_array($entry['tokens'] ?? null)
                ? $entry['tokens']
                : (is_array($entry['usage'] ?? null) ? $entry['usage'] : $entry);

            $indexed[$date] = [
                'generations' => $generations,
                'cost_nano' => $costNano,
                'tokens' => [
                    'input_text' => (int) ($tokens['input_text'] ?? $tokens['input_text_tokens'] ?? 0),
                    'input_image' => (int) ($tokens['input_image'] ?? $tokens['input_image_tokens'] ?? 0),
                    'output' => (int) ($tokens['output'] ?? $tokens['output_tokens'] ?? 0),
                    'reasoning' => (int) ($tokens['reasoning'] ?? $tokens['reasoning_tokens'] ?? 0),
                ],
            ];
        }

        return $indexed;
    }

    /**
     * @return array<string, array{
     *     generations: int,
     *     cost_nano: int,
     *     tokens: array{input_text: int, input_image: int, output: int, reasoning: int}
     * }>
     */
    private function aggregateLocalDays(Carbon $startUtc, Carbon $endUtc): array
    {
        $rows = Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$startUtc, $endUtc])
            ->get(['completed_at', 'provider_cost_nano', 'usage']);

        $days = [];

        foreach ($rows as $generation) {
            if ($generation->completed_at === null) {
                continue;
            }

            $date = $generation->completed_at->copy()->utc()->toDateString();
            $days[$date] ??= $this->emptyBucket();

            $days[$date]['generations']++;
            $days[$date]['cost_nano'] += (int) ($generation->provider_cost_nano ?? 0);

            $usage = $generation->usage ?? [];
            $days[$date]['tokens']['input_text'] += (int) ($usage['input_text'] ?? $usage['input_text_tokens'] ?? 0);
            $days[$date]['tokens']['input_image'] += (int) ($usage['input_image'] ?? $usage['input_image_tokens'] ?? 0);
            $days[$date]['tokens']['output'] += (int) ($usage['output'] ?? $usage['output_tokens'] ?? 0);
            $days[$date]['tokens']['reasoning'] += (int) ($usage['reasoning'] ?? $usage['reasoning_tokens'] ?? 0);
        }

        return $days;
    }

    /**
     * @return array{
     *     generations: int,
     *     cost_nano: int,
     *     tokens: array{input_text: int, input_image: int, output: int, reasoning: int}
     * }
     */
    private function emptyBucket(): array
    {
        return [
            'generations' => 0,
            'cost_nano' => 0,
            'tokens' => [
                'input_text' => 0,
                'input_image' => 0,
                'output' => 0,
                'reasoning' => 0,
            ],
        ];
    }
}
