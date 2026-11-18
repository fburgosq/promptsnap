<?php

namespace App\Services\Credits;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Nano;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreditLedgerService
{
    /**
     * Supported fixed credit packs in USD -> nanodollars.
     *
     * @var array<int, int>
     */
    public const array PACKS_USD_TO_NANO = [
        5 => 5 * Nano::PER_DOLLAR,
        10 => 10 * Nano::PER_DOLLAR,
        25 => 25 * Nano::PER_DOLLAR,
    ];

    /**
     * Purchase a fixed credit pack ($5, $10, or $25) and credit the user's ledger + balance
     * under a row lock in a single transaction.
     */
    public function purchasePack(User $user, int $packUsd): CreditTransaction
    {
        $amountNano = self::PACKS_USD_TO_NANO[$packUsd] ?? null;

        if ($amountNano === null) {
            throw new InvalidArgumentException("Unsupported credit pack: [\${$packUsd}].");
        }

        return DB::transaction(function () use ($user, $amountNano): CreditTransaction {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = CreditTransaction::query()->create([
                'user_id' => $lockedUser->id,
                'type' => CreditTransactionType::Purchase,
                'amount_nano' => $amountNano,
                'generation_id' => null,
            ]);

            $lockedUser->balance_nano += $amountNano;
            $lockedUser->save();

            $user->setRawAttributes($lockedUser->getAttributes(), true);
            $user->syncChanges();

            return $transaction;
        });
    }

    /**
     * Debit a succeeded generation's actual charge from the owner's balance and record
     * a single `generation_charge` ledger entry under a user row lock.
     *
     * Intended to be called inside `GenerationSettler::applyProviderSnapshot()`'s transaction
     * when transitioning into `succeeded`. Never charges more than the generation's reserved
     * `estimated_max_charge_nano`.
     */
    public function debitForSucceededGeneration(Generation $generation, int $chargedNano): int
    {
        $effectiveChargeNano = $generation->estimated_max_charge_nano > 0
            ? min($chargedNano, $generation->estimated_max_charge_nano)
            : $chargedNano;

        /** @var User $lockedUser */
        $lockedUser = User::query()
            ->whereKey($generation->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        CreditTransaction::query()->create([
            'user_id' => $lockedUser->id,
            'type' => CreditTransactionType::GenerationCharge,
            'amount_nano' => -$effectiveChargeNano,
            'generation_id' => $generation->id,
        ]);

        $lockedUser->balance_nano = max(0, $lockedUser->balance_nano - $effectiveChargeNano);
        $lockedUser->save();

        return $effectiveChargeNano;
    }

    /**
     * Reserve credits and create a queued Generation inside a single DB transaction
     * with the User row locked (`lockForUpdate()`).
     *
     * Available balance = user.balance_nano - SUM(estimated_max_charge_nano of user's queued/processing generations).
     * Throws InsufficientCreditsException if available < requiredMaxChargeNano.
     *
     * @template T
     *
     * @param  Closure(User): T  $createCallback
     * @return T
     */
    public function reserveAndCreateGeneration(
        User $user,
        int $requiredMaxChargeNano,
        Closure $createCallback,
    ): mixed {
        return DB::transaction(function () use ($user, $requiredMaxChargeNano, $createCallback) {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $reservedNano = $this->reservedNanoForUser($lockedUser->id);
            $availableNano = $lockedUser->balance_nano - $reservedNano;

            if ($availableNano < $requiredMaxChargeNano) {
                throw new InsufficientCreditsException(
                    availableNano: max(0, $availableNano),
                    requiredMaxChargeNano: $requiredMaxChargeNano,
                );
            }

            return $createCallback($lockedUser);
        });
    }

    public function reservedNanoForUser(int $userId): int
    {
        return (int) Generation::query()
            ->where('user_id', $userId)
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->sum('estimated_max_charge_nano');
    }

    public function availableNanoForUser(User $user): int
    {
        return max(0, $user->balance_nano - $this->reservedNanoForUser($user->id));
    }
}
