<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class ValidationFailed extends PixelforgeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message = 'Validation failed.',
        string $errorCode = 'validation_failed',
        int $status = 422,
        ?int $retryAfter = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
