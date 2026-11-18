<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

it('forbids non-admin users and redirects guests from GET /admin/report', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));

    $regularUser = User::factory()->create(['is_admin' => false]);

    expect(Gate::forUser($regularUser)->allows('admin'))->toBeFalse();

    $this->actingAs($regularUser)
        ->get(route('admin.report'))
        ->assertForbidden();
});

it('promotes a user to admin via the user:promote-admin command', function () {
    $user = User::factory()->create([
        'email' => 'promote-me@example.com',
        'is_admin' => false,
    ]);

    $this->artisan('user:promote-admin', ['email' => 'promote-me@example.com'])
        ->expectsOutputToContain('promoted to admin')
        ->assertSuccessful();

    expect($user->fresh()?->is_admin)->toBeTrue()
        ->and(Gate::forUser($user->fresh())->allows('admin'))->toBeTrue();
});

it('aggregates ONLY succeeded generations grouped by UTC completed_at date and by user with exact SQL nanodollar sums', function () {
    $admin = User::factory()->admin()->create();
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    // Alice: 2 succeeded on 2026-11-15 UTC (note: submitted on 2026-11-14 late night, completed on 2026-11-15)
    Generation::factory()->for($alice)->succeeded()->create([
        'provider_cost_nano' => 100_000_000,
        'charged_nano' => 125_000_000,
        'submitted_at' => Carbon::parse('2026-11-14T23:59:50Z'),
        'completed_at' => Carbon::parse('2026-11-15T00:00:05Z'),
    ]);
    Generation::factory()->for($alice)->succeeded()->create([
        'provider_cost_nano' => 200_000_000,
        'charged_nano' => 250_000_000,
        'completed_at' => Carbon::parse('2026-11-15T14:30:00Z'),
    ]);

    // Bob: 1 succeeded on 2026-11-16 UTC
    Generation::factory()->for($bob)->succeeded()->create([
        'provider_cost_nano' => 305_510_000,
        'charged_nano' => 381_887_500,
        'completed_at' => Carbon::parse('2026-11-16T10:00:00Z'),
    ]);

    // Excluded rows: failed, processing, queued, and succeeded outside the date range
    Generation::factory()->for($alice)->failed()->create([
        'completed_at' => Carbon::parse('2026-11-15T15:00:00Z'),
    ]);
    Generation::factory()->for($bob)->processing()->create([
        'started_at' => Carbon::parse('2026-11-16T11:00:00Z'),
    ]);
    Generation::factory()->for($alice)->create([
        'status' => GenerationStatus::Queued,
    ]);
    Generation::factory()->for($alice)->succeeded()->create([
        'provider_cost_nano' => 999_000_000,
        'charged_nano' => 999_000_000,
        'completed_at' => Carbon::parse('2026-10-01T12:00:00Z'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-18',
            'view' => 'by_day',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            // Totals: 3 gens, provider = 605,510,000 ($0.60551), charged = 756,887,500 ($0.7568875), profit = 151,377,500 ($0.1513775)
            ->where('totals.generations_count', 3)
            ->where('totals.provider_cost_nano', 605_510_000)
            ->where('totals.provider_cost_formatted', '$0.60551')
            ->where('totals.charged_nano', 756_887_500)
            ->where('totals.charged_formatted', '$0.7568875')
            ->where('totals.profit_nano', 151_377_500)
            ->where('totals.profit_formatted', '$0.1513775')
            // By day (ordered newest day first: 2026-11-16, then 2026-11-15)
            ->has('by_day', 2)
            ->where('by_day.0.date', '2026-11-16')
            ->where('by_day.0.generations_count', 1)
            ->where('by_day.0.charged_nano', 381_887_500)
            ->where('by_day.1.date', '2026-11-15')
            ->where('by_day.1.generations_count', 2)
            ->where('by_day.1.charged_nano', 375_000_000)
            ->where('by_day.1.provider_cost_nano', 300_000_000)
            ->where('by_day.1.profit_nano', 75_000_000)
            // By user (ordered by highest spend: Bob 381.8875m, Alice 375m)
            ->has('by_user', 2)
            ->where('by_user.0.user_id', $bob->id)
            ->where('by_user.0.generations_count', 1)
            ->where('by_user.0.charged_nano', 381_887_500)
            ->where('by_user.1.user_id', $alice->id)
            ->where('by_user.1.generations_count', 2)
            ->where('by_user.1.charged_nano', 375_000_000)
        );
});

it('rejects date ranges exceeding 92 days on GET /admin/report', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('admin.report'))
        ->get(route('admin.report', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-05', // 95 days inclusive
        ]))
        ->assertRedirect(route('admin.report'))
        ->assertSessionHasErrors(['end_date']);
});
