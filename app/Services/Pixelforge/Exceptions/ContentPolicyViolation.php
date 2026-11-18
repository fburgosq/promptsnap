<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

final class ContentPolicyViolation extends PixelforgeException
{
    public function __construct(
        string $message = 'Prompt violated content policy.',
        string $errorCode = 'content_policy_violation',
        int $status = 400,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $retryAfter, $previous);
    }
}
