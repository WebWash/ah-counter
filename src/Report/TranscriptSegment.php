<?php

declare(strict_types=1);

namespace App\Report;

use App\Analysis\FillerOccurrence;
use App\Transcription\Word;

/**
 * A run of Transcript words to show together: one plain word, or all the words of one Filler
 * Sound or Candidate ("you know").
 */
final readonly class TranscriptSegment
{
    /**
     * @param non-empty-array<int, Word> $words keyed by their index in the Transcript
     */
    public function __construct(
        public array $words,
        public ?FillerOccurrence $occurrence,
    ) {
    }

    public function start(): float
    {
        return $this->words[array_key_first($this->words)]->start;
    }
}
