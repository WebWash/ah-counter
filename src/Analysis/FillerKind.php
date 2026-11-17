<?php

declare(strict_types=1);

namespace App\Analysis;

enum FillerKind: string
{
    /** A non-word vocalisation: always a Filler. */
    case Sound = 'sound';

    /** A real word or phrase: a Filler only when the surrounding words show it. */
    case Phrase = 'phrase';

    public function label(): string
    {
        return match ($this) {
            self::Sound => 'Filler Sound',
            self::Phrase => 'Filler Phrase',
        };
    }
}
