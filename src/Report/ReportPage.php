<?php

declare(strict_types=1);

namespace App\Report;

use App\Analysis\FillerKind;

/**
 * Everything the Report page shows for a done Speech: the statistics, the Transcript with
 * Fillers marked, and each counted Filler in context.
 */
final readonly class ReportPage
{
    /**
     * @param list<TranscriptSegment> $segments
     * @param list<CountedFiller>     $countedFillers in time order
     */
    public function __construct(
        public Report $report,
        public array $segments,
        public array $countedFillers,
    ) {
    }

    /**
     * Whether a counted Filler Type is a Filler Sound or a Filler Phrase.
     */
    public function kindOf(string $type): FillerKind
    {
        foreach ($this->countedFillers as $counted) {
            if ($counted->filler->type === $type) {
                return $counted->filler->kind;
            }
        }

        return FillerKind::Phrase;
    }
}
