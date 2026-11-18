<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class ConnectionFailed extends PixelforgeException
{
    public function __construct(
        string $message = 'Failed to connect to Pixelforge.',
        string $errorCode = 'connection_failed',
        int $status = 0,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
