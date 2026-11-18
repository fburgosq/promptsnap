<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class RateLimited extends PixelforgeException
{
    public function __construct(
        string $message = 'Rate limit exceeded.',
        string $errorCode = 'rate_limit_exceeded',
        int $status = 429,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
