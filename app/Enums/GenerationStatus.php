<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            self::Queued, self::Processing => false,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        return $target->rank() > $this->rank();
    }
}
