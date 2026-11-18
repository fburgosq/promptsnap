<?php

use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

it('passes with exit code 0 when local succeeded generations match Pixelforge GET /v1/usage exactly', function () {
    Http::preventStrayRequests();

    // 3 succeeded generations on 2026-11-15 totaling 41 text, 0 img, 3300 output, 2950 reasoning = 169,080,000 nano ($0.169080000)
    Generation::factory()->succeeded()->create([
        'provider_cost_nano' => 60_000_000,
        'usage' => ['input_text' => 15, 'input_image' => 0, 'output' => 1100, 'reasoning' => 1000],
        'completed_at' => Carbon::parse('2026-11-15T08:00:00Z'),
    ]);
    Generation::factory()->succeeded()->create([
        'provider_cost_nano' => 55_000_000,
        'usage' => ['input_text' => 14, 'input_image' => 0, 'output' => 1100, 'reasoning' => 1000],
        'completed_at' => Carbon::parse('2026-11-15T12:00:00Z'),
    ]);
    Generation::factory()->succeeded()->create([
        'provider_cost_nano' => 54_080_000,
        'usage' => ['input_text' => 12, 'input_image' => 0, 'output' => 1100, 'reasoning' => 950],
        'completed_at' => Carbon::parse('2026-11-15T19:00:00Z'),
    ]);

    // A failed generation on the same day should be ignored
    Generation::factory()->failed()->create([
        'completed_at' => Carbon::parse('2026-11-15T20:00:00Z'),
    ]);

    Http::fake([
        '*/usage?start_date=2026-11-14&end_date=2026-11-16' => Http::response([
            'days' => [
                [
                    'date' => '2026-11-15',
                    'generations' => 3,
                    'cost_usd' => '0.169080000',
                    'tokens' => [
                        'input_text' => 41,
                        'input_image' => 0,
                        'output' => 3300,
                        'reasoning' => 2950,
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('pixelforge:usage-check', [
        '--from' => '2026-11-14',
        '--to' => '2026-11-16',
    ])
        ->expectsOutputToContain('[2026-11-15] OK — 3 generations, provider cost $0.16908')
        ->expectsOutputToContain('Usage reconciliation passed with zero mismatches.')
        ->assertSuccessful();
});

it('prints detailed mismatches and exits with failure code when cost, generation count, or token totals diverge', function () {
    Http::preventStrayRequests();

    Generation::factory()->succeeded()->create([
        'provider_cost_nano' => 305_510_000,
        'usage' => [
            'input_text' => 12,
            'input_image' => 765,
            'output' => 6450,
            'reasoning' => 3184,
        ],
        'completed_at' => Carbon::parse('2026-11-16T10:00:00Z'),
    ]);

    Http::fake([
        '*/usage?start_date=2026-11-16&end_date=2026-11-16' => Http::response([
            'days' => [
                [
                    'date' => '2026-11-16',
                    'generations' => 2,
                    'cost_usd' => '0.450000000',
                    'tokens' => [
                        'input_text' => 25,
                        'input_image' => 765,
                        'output' => 12900,
                        'reasoning' => 5000,
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('pixelforge:usage-check', [
        '--from' => '2026-11-16',
        '--to' => '2026-11-16',
    ])
        ->expectsOutputToContain('[2026-11-16] MISMATCH: cost_usd mismatch (provider: $0.4500 [450000000 nano] vs local: $0.30551 [305510000 nano]); generations mismatch (provider: 2 vs local: 1); input_text tokens mismatch (provider: 25 vs local: 12); output tokens mismatch (provider: 12900 vs local: 6450); reasoning tokens mismatch (provider: 5000 vs local: 3184)')
        ->expectsOutputToContain('Usage reconciliation found 1 day(s) with mismatches.')
        ->assertFailed();
});
