<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class DailyLimitExceeded extends PixelforgeException
{
    public function __construct(
        string $message = 'Daily limit exceeded.',
        string $errorCode = 'daily_limit_exceeded',
        int $status = 429,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
