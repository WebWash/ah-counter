<?php

declare(strict_types=1);

namespace App\Coaching;

use App\Report\Report;

/**
 * Which Filler Type a Practice Exercise targets (SPEC §6.6): the one asked for, or else the
 * Report's most frequent one; null for a general fluency exercise when there are no Fillers.
 */
final class PracticeFocus
{
    /**
     * @throws FillerTypeNotInSpeech when the Speech has no Filler of the requested type
     */
    public static function choose(Report $report, ?string $requested): ?string
    {
        if (null === $requested) {
            return $report->topType;
        }

        $focus = mb_strtolower(trim($requested));
        $types = array_map(strval(...), array_keys($report->byType));

        return \in_array($focus, $types, true) ? $focus : throw new FillerTypeNotInSpeech($focus, $types);
    }
}
