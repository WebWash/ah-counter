<?php

declare(strict_types=1);

namespace App\Report;

use App\Analysis\FillerOccurrence;
use App\Transcription\Transcript;
use App\Transcription\Word;

/**
 * Lines a Transcript up with its Filler Sounds and Candidates, for showing the Transcript with
 * Fillers marked and each Filler in context.
 */
final class TranscriptMarker
{
    /**
     * @param list<FillerOccurrence> $occurrences in time order, never overlapping (as FillerDetector makes them)
     *
     * @return list<TranscriptSegment> in Transcript order, covering every word once
     */
    public function segments(Transcript $transcript, array $occurrences): array
    {
        $byFirstWord = [];
        foreach ($occurrences as $occurrence) {
            $byFirstWord[$occurrence->wordStart] = $occurrence;
        }

        $segments = [];
        $count = \count($transcript->words);
        for ($i = 0; $i < $count;) {
            $occurrence = $byFirstWord[$i] ?? null;
            $last = null === $occurrence ? $i : min($occurrence->wordEnd, $count - 1);

            $words = [];
            for ($j = $i; $j <= $last; ++$j) {
                $words[$j] = $transcript->words[$j];
            }
            $segments[] = new TranscriptSegment($words, $occurrence);
            $i = $last + 1;
        }

        return $segments;
    }

    /**
     * The Filler's words with up to $around words either side, punctuated as spoken. An ellipsis
     * marks where the Transcript goes on.
     *
     * @return array{before: string, filler: string, after: string}
     */
    public function context(Transcript $transcript, FillerOccurrence $occurrence, int $around = 4): array
    {
        $words = $transcript->words;
        $from = max(0, $occurrence->wordStart - $around);
        $to = min(\count($words) - 1, $occurrence->wordEnd + $around);

        $before = $this->join(\array_slice($words, $from, $occurrence->wordStart - $from));
        $after = $this->join(\array_slice($words, $occurrence->wordEnd + 1, $to - $occurrence->wordEnd));

        return [
            'before' => ($from > 0 && '' !== $before ? '… ' : '').$before,
            'filler' => $this->join(\array_slice($words, $occurrence->wordStart, $occurrence->wordEnd - $occurrence->wordStart + 1)),
            'after' => $after.($to < \count($words) - 1 && '' !== $after ? ' …' : ''),
        ];
    }

    /**
     * @param list<Word> $words
     */
    private function join(array $words): string
    {
        return implode(' ', array_map(static fn (Word $word): string => $word->punctuated, $words));
    }
}
