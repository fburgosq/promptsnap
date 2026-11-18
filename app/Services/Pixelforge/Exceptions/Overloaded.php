<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class Overloaded extends PixelforgeException
{
    public function __construct(
        string $message = 'Service is temporarily overloaded.',
        string $errorCode = 'overloaded',
        int $status = 503,
        ?int $retryAfter = 2,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
