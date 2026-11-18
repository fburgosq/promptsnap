<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class InvalidApiKey extends PixelforgeException
{
    public function __construct(
        string $message = 'Invalid API key.',
        string $errorCode = 'invalid_api_key',
        int $status = 401,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
