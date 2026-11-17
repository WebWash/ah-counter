<?php

declare(strict_types=1);

namespace App\Report;

use App\Analysis\FillerOccurrence;

/**
 * A Filler counted in the Report, with a few words either side of it.
 */
final readonly class CountedFiller
{
    /**
     * @param array{before: string, filler: string, after: string} $context
     */
    public function __construct(
        public FillerOccurrence $filler,
        public array $context,
    ) {
    }
}
