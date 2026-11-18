<?php

namespace App\Services\Admin;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Nano;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReconciliationReportService
{
    /**
     * Build the reconciliation report for succeeded generations completed within [startDate, endDate] (UTC).
     *
     * All sums are computed in SQL on integer *_nano columns and formatted on the server via Nano.
     *
     * @return array{
     *     filters: array{start_date: string, end_date: string, view: string},
     *     totals: array{
     *         generations_count: int,
     *         charged_nano: int,
     *         charged_formatted: string,
     *         provider_cost_nano: int,
     *         provider_cost_formatted: string,
     *         profit_nano: int,
     *         profit_formatted: string
     *     },
     *     by_day: list<array{
     *         date: string,
     *         generations_count: int,
     *         charged_nano: int,
     *         charged_formatted: string,
     *         provider_cost_nano: int,
     *         provider_cost_formatted: string,
     *         profit_nano: int,
     *         profit_formatted: string
     *     }>,
     *     by_user: list<array{
     *         user_id: int,
     *         user_name: string,
     *         user_email: string,
     *         generations_count: int,
     *         charged_nano: int,
     *         charged_formatted: string,
     *         provider_cost_nano: int,
     *         provider_cost_formatted: string,
     *         profit_nano: int,
     *         profit_formatted: string
     *     }>,
     *     by_day_and_user: list<array{
     *         date: string,
     *         user_id: int,
     *         user_name: string,
     *         user_email: string,
     *         generations_count: int,
     *         charged_nano: int,
     *         charged_formatted: string,
     *         provider_cost_nano: int,
     *         provider_cost_formatted: string,
     *         profit_nano: int,
     *         profit_formatted: string
     *     }>
     * }
     */
    public function generate(string $startDate, string $endDate, string $view = 'by_day'): array
    {
        $startUtc = Carbon::createFromFormat('Y-m-d', $startDate, 'UTC')->startOfDay();
        $endUtc = Carbon::createFromFormat('Y-m-d', $endDate, 'UTC')->endOfDay();

        // 1. Totals row in SQL
        $totalsRow = Generation::query()
            ->toBase()
            ->where('status', GenerationStatus::Succeeded->value)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$startUtc, $endUtc])
            ->selectRaw('COUNT(*) as generations_count')
            ->selectRaw('COALESCE(SUM(charged_nano), 0) as charged_nano')
            ->selectRaw('COALESCE(SUM(provider_cost_nano), 0) as provider_cost_nano')
            ->selectRaw('COALESCE(SUM(charged_nano) - SUM(provider_cost_nano), 0) as profit_nano')
            ->first();

        $totals = $this->formatAggregateRow(
            generationsCount: (int) ($totalsRow->generations_count ?? 0),
            chargedNano: (int) ($totalsRow->charged_nano ?? 0),
            providerCostNano: (int) ($totalsRow->provider_cost_nano ?? 0),
            profitNano: (int) ($totalsRow->profit_nano ?? 0),
        );

        // 2. Grouped by UTC billing day (DATE(completed_at)) in SQL
        $dayRows = Generation::query()
            ->toBase()
            ->where('status', GenerationStatus::Succeeded->value)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$startUtc, $endUtc])
            ->selectRaw('DATE(completed_at) as billing_date')
            ->selectRaw('COUNT(*) as generations_count')
            ->selectRaw('COALESCE(SUM(charged_nano), 0) as charged_nano')
            ->selectRaw('COALESCE(SUM(provider_cost_nano), 0) as provider_cost_nano')
            ->selectRaw('COALESCE(SUM(charged_nano) - SUM(provider_cost_nano), 0) as profit_nano')
            ->groupBy(DB::raw('DATE(completed_at)'))
            ->orderByDesc('billing_date')
            ->get();

        $byDay = [];
        foreach ($dayRows as $row) {
            $byDay[] = [
                'date' => (string) $row->billing_date,
                ...$this->formatAggregateRow(
                    generationsCount: (int) $row->generations_count,
                    chargedNano: (int) $row->charged_nano,
                    providerCostNano: (int) $row->provider_cost_nano,
                    profitNano: (int) $row->profit_nano,
                ),
            ];
        }

        // 3. Grouped by User in SQL
        $userRows = Generation::query()
            ->toBase()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereNotNull('generations.completed_at')
            ->whereBetween('generations.completed_at', [$startUtc, $endUtc])
            ->select('users.id as user_id', 'users.name as user_name', 'users.email as user_email')
            ->selectRaw('COUNT(generations.id) as generations_count')
            ->selectRaw('COALESCE(SUM(generations.charged_nano), 0) as charged_nano')
            ->selectRaw('COALESCE(SUM(generations.provider_cost_nano), 0) as provider_cost_nano')
            ->selectRaw('COALESCE(SUM(generations.charged_nano) - SUM(generations.provider_cost_nano), 0) as profit_nano')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('charged_nano')
            ->orderBy('users.id')
            ->get();

        $byUser = [];
        foreach ($userRows as $row) {
            $byUser[] = [
                'user_id' => (int) $row->user_id,
                'user_name' => (string) $row->user_name,
                'user_email' => (string) $row->user_email,
                ...$this->formatAggregateRow(
                    generationsCount: (int) $row->generations_count,
                    chargedNano: (int) $row->charged_nano,
                    providerCostNano: (int) $row->provider_cost_nano,
                    profitNano: (int) $row->profit_nano,
                ),
            ];
        }

        // 4. Grouped by both UTC day AND user in SQL
        $dayUserRows = Generation::query()
            ->toBase()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereNotNull('generations.completed_at')
            ->whereBetween('generations.completed_at', [$startUtc, $endUtc])
            ->select('users.id as user_id', 'users.name as user_name', 'users.email as user_email')
            ->selectRaw('DATE(generations.completed_at) as billing_date')
            ->selectRaw('COUNT(generations.id) as generations_count')
            ->selectRaw('COALESCE(SUM(generations.charged_nano), 0) as charged_nano')
            ->selectRaw('COALESCE(SUM(generations.provider_cost_nano), 0) as provider_cost_nano')
            ->selectRaw('COALESCE(SUM(generations.charged_nano) - SUM(generations.provider_cost_nano), 0) as profit_nano')
            ->groupBy(DB::raw('DATE(generations.completed_at)'), 'users.id', 'users.name', 'users.email')
            ->orderByDesc('billing_date')
            ->orderByDesc('charged_nano')
            ->get();

        $byDayAndUser = [];
        foreach ($dayUserRows as $row) {
            $byDayAndUser[] = [
                'date' => (string) $row->billing_date,
                'user_id' => (int) $row->user_id,
                'user_name' => (string) $row->user_name,
                'user_email' => (string) $row->user_email,
                ...$this->formatAggregateRow(
                    generationsCount: (int) $row->generations_count,
                    chargedNano: (int) $row->charged_nano,
                    providerCostNano: (int) $row->provider_cost_nano,
                    profitNano: (int) $row->profit_nano,
                ),
            ];
        }

        return [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'view' => $view,
            ],
            'totals' => $totals,
            'by_day' => $byDay,
            'by_user' => $byUser,
            'by_day_and_user' => $byDayAndUser,
        ];
    }

    /**
     * @return array{
     *     generations_count: int,
     *     charged_nano: int,
     *     charged_formatted: string,
     *     provider_cost_nano: int,
     *     provider_cost_formatted: string,
     *     profit_nano: int,
     *     profit_formatted: string
     * }
     */
    private function formatAggregateRow(
        int $generationsCount,
        int $chargedNano,
        int $providerCostNano,
        int $profitNano,
    ): array {
        return [
            'generations_count' => $generationsCount,
            'charged_nano' => $chargedNano,
            'charged_formatted' => Nano::format($chargedNano),
            'provider_cost_nano' => $providerCostNano,
            'provider_cost_formatted' => Nano::format($providerCostNano),
            'profit_nano' => $profitNano,
            'profit_formatted' => Nano::format($profitNano),
        ];
    }
}
