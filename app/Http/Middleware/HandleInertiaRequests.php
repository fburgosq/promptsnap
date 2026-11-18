<?php

namespace App\Http\Middleware;

use App\Services\Credits\CreditLedgerService;
use App\Support\Nano;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $creditsShared = null;

        if ($user !== null) {
            $ledger = app(CreditLedgerService::class);
            $reservedNano = $ledger->reservedNanoForUser($user->id);
            $availableNano = max(0, $user->balance_nano - $reservedNano);

            $creditsShared = [
                'balance_nano' => $user->balance_nano,
                'balance_formatted' => Nano::format($user->balance_nano),
                'reserved_nano' => $reservedNano,
                'reserved_formatted' => Nano::format($reservedNano),
                'available_nano' => $availableNano,
                'available_formatted' => Nano::format($availableNano),
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'credits' => $creditsShared,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
