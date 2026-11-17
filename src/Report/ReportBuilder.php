<?php

declare(strict_types=1);

namespace App\Report;

use App\Analysis\FillerKind;
use App\Entity\Speech;
use App\Entity\SpeechStatus;

/**
 * The one place that turns a Speech into a Report (SPEC §6.4), shared by the coaching step,
 * the web pages and the MCP tools.
 *
 * It works for any status once the duration and Fillers are saved. Showing Reports only for
 * done Speeches is the caller's rule, not this one's.
 */
final class ReportBuilder
{
    /**
     * @throws ReportNotAvailable when the duration or Fillers aren't saved yet
     */
    public function build(Speech $speech): Report
    {
        return $this->buildIfAvailable($speech) ?? throw new ReportNotAvailable($speech);
    }

    /**
     * The Report as the web pages and MCP tools show it: only for done Speeches (SPEC §6.4).
     */
    public function buildIfDone(Speech $speech): ?Report
    {
        return SpeechStatus::Done === $speech->getStatus() ? $this->buildIfAvailable($speech) : null;
    }

    public function buildIfAvailable(Speech $speech): ?Report
    {
        $duration = $speech->getDurationSeconds();
        $occurrences = $speech->fillerOccurrences();
        if (null === $duration || $duration <= 0.0 || null === $occurrences) {
            return null;
        }

        $byType = [];
        $soundCount = 0;
        $phraseCount = 0;
        foreach ($occurrences as $occurrence) {
            if (!$occurrence->isFiller()) {
                continue;
            }
            $byType[$occurrence->type] = ($byType[$occurrence->type] ?? 0) + 1;
            FillerKind::Sound === $occurrence->kind ? ++$soundCount : ++$phraseCount;
        }

        // Most first; ties alphabetical. Keys are cast to string: PHP would turn "1" into an int.
        uksort($byType, static fn (string|int $a, string|int $b): int => [$byType[$b], (string) $a] <=> [$byType[$a], (string) $b]);

        $total = $soundCount + $phraseCount;
        $perMinute = $total / ($duration / 60);

        return new Report(
            $duration,
            $total,
            $perMinute,
            round($perMinute, 1),
            $byType,
            $soundCount,
            $phraseCount,
            [] === $byType ? null : (string) array_key_first($byType),
            $speech->coaching(),
        );
    }
}
