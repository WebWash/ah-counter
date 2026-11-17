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
     * Rate changes this close count as equal, so float noise never decides a tie.
     */
    private const int TIE_PRECISION = 9;

    public function compare(Report $baseline, Report $later): SpeechComparison
    {
        $types = array_unique([...array_map(strval(...), array_keys($baseline->byType)), ...array_map(strval(...), array_keys($later->byType))]);

        $changes = [];
        foreach ($types as $type) {
            $changes[] = new FillerTypeChange(
                $type,
                $baseline->byType[$type] ?? 0,
                $later->byType[$type] ?? 0,
                $this->perMinute($baseline->byType[$type] ?? 0, $baseline),
                $this->perMinute($later->byType[$type] ?? 0, $later),
            );
        }

        // Most improved first, most worsened last; ties alphabetical.
        usort($changes, fn (FillerTypeChange $a, FillerTypeChange $b): int => [$this->rateChange($a), $a->type] <=> [$this->rateChange($b), $b->type]);

        $improved = array_values(array_filter($changes, fn (FillerTypeChange $c): bool => $this->rateChange($c) < 0));
        $worsened = array_values(array_filter($changes, fn (FillerTypeChange $c): bool => $this->rateChange($c) > 0));
        usort($worsened, fn (FillerTypeChange $a, FillerTypeChange $b): int => [$this->rateChange($b), $a->type] <=> [$this->rateChange($a), $b->type]);

        return new SpeechComparison(
            $baseline->durationSeconds,
            $later->durationSeconds,
            $baseline->totalFillers,
            $later->totalFillers,
            $baseline->fillersPerMinute,
            $later->fillersPerMinute,
            $changes,
            $improved[0]->type ?? null,
            $worsened[0]->type ?? null,
        );
    }

    private function perMinute(int $count, Report $report): float
    {
        return $count / ($report->durationSeconds / 60);
    }

    /**
     * The change in rate, rounded so that equal changes compare equal.
     */
    private function rateChange(FillerTypeChange $change): float
    {
        return round($change->perMinuteChange, self::TIE_PRECISION);
    }
}
