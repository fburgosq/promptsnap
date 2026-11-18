<?php

namespace App\Http\Controllers;

use App\Http\Requests\Credits\PurchaseCreditsRequest;
use App\Services\Credits\CreditLedgerService;
use App\Support\Nano;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request, CreditLedgerService $creditLedger): Response
    {
        $user = $request->user();
        $reservedNano = $creditLedger->reservedNanoForUser($user->id);
        $availableNano = max(0, $user->balance_nano - $reservedNano);

        $transactions = $user->creditTransactions()
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn ($tx): array => [
                'id' => $tx->id,
                'type' => $tx->type->value,
                'amount_nano' => $tx->amount_nano,
                'amount_formatted' => ($tx->amount_nano >= 0 ? '+' : '-').Nano::format(abs($tx->amount_nano)),
                'generation_id' => $tx->generation_id,
                'created_at_formatted' => $tx->created_at?->utc()->format('M j, Y H:i:s \U\T\C'),
            ]);

        return Inertia::render('credits/Index', [
            'balance_nano' => $user->balance_nano,
            'balance_formatted' => Nano::format($user->balance_nano),
            'reserved_nano' => $reservedNano,
            'reserved_formatted' => Nano::format($reservedNano),
            'available_nano' => $availableNano,
            'available_formatted' => Nano::format($availableNano),
            'packs' => [
                ['usd' => 5, 'label' => '$5.00 Pack', 'nano' => CreditLedgerService::PACKS_USD_TO_NANO[5]],
                ['usd' => 10, 'label' => '$10.00 Pack', 'nano' => CreditLedgerService::PACKS_USD_TO_NANO[10]],
                ['usd' => 25, 'label' => '$25.00 Pack', 'nano' => CreditLedgerService::PACKS_USD_TO_NANO[25]],
            ],
            'transactions' => $transactions,
        ]);
    }

    public function store(
        PurchaseCreditsRequest $request,
        CreditLedgerService $creditLedger,
    ): RedirectResponse {
        $packUsd = (int) $request->validated('pack_usd');

        $creditLedger->purchasePack($request->user(), $packUsd);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Added \${$packUsd}.00 in credits to your balance.",
        ]);

        return back();
    }
}
