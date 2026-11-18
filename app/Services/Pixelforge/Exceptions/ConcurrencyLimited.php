<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class ConcurrencyLimited extends PixelforgeException
{
    public function __construct(
        string $message = 'Concurrency limit exceeded.',
        string $errorCode = 'concurrency_limit_exceeded',
        int $status = 429,
        ?int $retryAfter = 5,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
