<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where a Speech is in the processing pipeline (SPEC §4).
 *
 * pending → transcribing → analysing → coaching → done; any non-terminal state → failed;
 * failed → pending only through SpeechRepository::resetFailedForRetry().
 */
enum SpeechStatus: string
{
    case Pending = 'pending';
    case Transcribing = 'transcribing';
    case Analysing = 'analysing';
    case Coaching = 'coaching';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * Position in the pipeline. Failed has none: it sits outside the order.
     */
    public function order(): ?int
    {
        return match ($this) {
            self::Pending => 0,
            self::Transcribing => 1,
            self::Analysing => 2,
            self::Coaching => 3,
            self::Done => 4,
            self::Failed => null,
        };
    }

    /**
     * The three statuses that name a processing step.
     */
    public function isStep(): bool
    {
        return \in_array($this, [self::Transcribing, self::Analysing, self::Coaching], true);
    }

    public function isFinished(): bool
    {
        return self::Done === $this || self::Failed === $this;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
