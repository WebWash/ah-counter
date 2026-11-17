<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * The one duration rule (SPEC §4): a Speech is 10–300 s long, accepted with a ±1 s tolerance.
 * The recorder, the upload and the pipeline all apply it.
 */
final class SpeechDuration
{
    public const float MIN_ACCEPTED_SECONDS = 9.0;
    public const float MAX_ACCEPTED_SECONDS = 301.0;
    public const string OUT_OF_RANGE_MESSAGE = 'A Speech must be between 10 seconds and 5 minutes long.';

    public static function isAccepted(float $seconds): bool
    {
        return $seconds >= self::MIN_ACCEPTED_SECONDS && $seconds <= self::MAX_ACCEPTED_SECONDS;
    }
}
