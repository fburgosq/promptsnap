<?php

namespace App\Services\Credits;

use RuntimeException;

final class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $availableNano,
        public readonly int $requiredMaxChargeNano,
        string $message = 'Insufficient credits to cover the maximum estimated generation cost.',
    ) {
        parent::__construct($message);
    }
}
