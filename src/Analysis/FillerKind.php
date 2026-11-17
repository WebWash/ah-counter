<?php

declare(strict_types=1);

namespace App\Analysis;

enum FillerKind: string
{
    /** A non-word vocalisation: always a Filler. */
    case Sound = 'sound';

    /** A real word or phrase: a Filler only when the surrounding words show it. */
    case Phrase = 'phrase';
}
