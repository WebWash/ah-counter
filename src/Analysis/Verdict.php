<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Whether an occurrence counts as a Filler (SPEC §4). Only Filler counts in the Report.
 */
enum Verdict: string
{
    case Filler = 'filler';

    /** A Candidate the judge decided is a genuine use of the word. */
    case NotFiller = 'not_filler';

    /** A Candidate not (yet) judged. Shown like NotFiller and not counted. */
    case Unjudged = 'unjudged';
}
