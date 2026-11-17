<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Compares a baseline Speech A with a later Speech B (SPEC §6.7). Pure maths, no AI.
 *
 * Every difference is B − A, from unrounded per-minute rates, so a negative change is an
 * improvement. Comparing rates rather than counts keeps Speeches of different lengths fair.
 */
final class SpeechComparer
{
    /**
     * Rates this close count as equal, so float noise never decides a tie.
     */
    private const int TIE_PRECISION = 9;

    public function compare(Report $baseline, Report $speech): SpeechComparison
    {
        $types = array_unique([...array_map(strval(...), array_keys($baseline->byType)), ...array_map(strval(...), array_keys($speech->byType))]);

        $changes = [];
        foreach ($types as $type) {
            $changes[] = new FillerTypeChange(
                $type,
                $baseline->byType[$type] ?? 0,
                $speech->byType[$type] ?? 0,
                $this->perMinute($baseline->byType[$type] ?? 0, $baseline),
                $this->perMinute($speech->byType[$type] ?? 0, $speech),
            );
        }

        // Most improved first, most worsened last; ties alphabetical.
        usort($changes, fn (FillerTypeChange $a, FillerTypeChange $b): int => [$this->key($a), $a->type] <=> [$this->key($b), $b->type]);

        $improved = array_values(array_filter($changes, fn (FillerTypeChange $c): bool => $this->key($c) < 0));
        $worsened = array_values(array_filter($changes, fn (FillerTypeChange $c): bool => $this->key($c) > 0));
        usort($worsened, fn (FillerTypeChange $a, FillerTypeChange $b): int => [$this->key($b), $a->type] <=> [$this->key($a), $b->type]);

        return new SpeechComparison(
            $baseline->durationSeconds,
            $speech->durationSeconds,
            $baseline->totalFillers,
            $speech->totalFillers,
            $baseline->fillersPerMinute,
            $speech->fillersPerMinute,
            $changes,
            $improved[0]->type ?? null,
            $worsened[0]->type ?? null,
        );
    }

    private function perMinute(int $count, Report $report): float
    {
        return $count / ($report->durationSeconds / 60);
    }

    private function key(FillerTypeChange $change): float
    {
        return round($change->perMinuteChange, self::TIE_PRECISION);
    }
}
