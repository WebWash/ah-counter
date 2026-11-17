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
     * The punctuated Transcript with each counted Filler in [brackets], punctuation kept outside:
     * "So I [um], think it was [like] huge." Candidates that don't count stay plain (SPEC §8).
     *
     * @param list<FillerOccurrence> $occurrences in time order, never overlapping
     */
    public function bracketed(Transcript $transcript, array $occurrences): string
    {
        $parts = [];
        foreach ($this->segments($transcript, $occurrences) as $segment) {
            $text = $this->join(array_values($segment->words));
            $parts[] = true === $segment->occurrence?->isFiller() ? $this->bracket($text) : $text;
        }

        return implode(' ', $parts);
    }

    /**
     * context() as one line with the Filler in [brackets]: "… think it was [like] huge and you …".
     */
    public function contextText(Transcript $transcript, FillerOccurrence $occurrence, int $around = 4): string
    {
        $context = $this->context($transcript, $occurrence, $around);

        return trim($context['before'].' '.$this->bracket($context['filler']).' '.$context['after']);
    }

    /**
     * "um," → "[um],": the brackets go round the words, not the punctuation.
     */
    private function bracket(string $text): string
    {
        preg_match('/^([^\p{L}\p{N}]*)(.*?)([^\p{L}\p{N}]*)$/us', $text, $match);

        return $match[1].'['.$match[2].']'.$match[3];
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
