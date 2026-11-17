<?php

declare(strict_types=1);

namespace App\Report;

use App\Entity\Speech;
use App\Transcription\Transcript;

/**
 * Assembles the Report page for a done Speech (SPEC §7 "Report page layout").
 */
final readonly class ReportPageBuilder
{
    public function __construct(
        private ReportBuilder $reportBuilder,
        private TranscriptMarker $marker,
    ) {
    }

    /**
     * Null unless the Speech is done and its Fillers are saved.
     */
    public function build(Speech $speech): ?ReportPage
    {
        $report = $this->reportBuilder->buildIfDone($speech);
        if (null === $report) {
            return null;
        }

        $transcript = $speech->transcript() ?? new Transcript([], $report->durationSeconds);
        $occurrences = $speech->fillerOccurrences() ?? [];

        $counted = [];
        foreach ($occurrences as $occurrence) {
            if ($occurrence->isFiller()) {
                $counted[] = new CountedFiller($occurrence, $this->marker->context($transcript, $occurrence));
            }
        }

        return new ReportPage($report, $this->marker->segments($transcript, $occurrences), $counted);
    }
}
