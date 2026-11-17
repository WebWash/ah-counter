<?php

declare(strict_types=1);

namespace App\Speech;

use App\Entity\SpeechStatus;

final class InvalidStatusTransition extends \LogicException
{
    public static function between(SpeechStatus $from, SpeechStatus $to): self
    {
        return new self(\sprintf('A Speech cannot move from "%s" to "%s".', $from->value, $to->value));
    }
}
