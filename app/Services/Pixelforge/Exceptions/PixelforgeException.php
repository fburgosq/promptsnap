<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unknown_error',
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function context(): array
    {
        return [
            'error_code' => $this->errorCode,
            'status' => $this->status,
            'retry_after' => $this->retryAfter,
        ];
    }
}
