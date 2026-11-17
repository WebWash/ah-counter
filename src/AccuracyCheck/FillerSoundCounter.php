<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

use App\Analysis\Lexicon;
use App\Transcription\Word;

/**
 * Counts Filler Sounds in a transcriber's raw words, by Filler Type (SPEC §4 lexicon).
 *
 * Stand-in for the transcription test (ticket 04) only: it works on bare words, so it also serves
 * Whisper, which has no Transcript. Replace this with Analysis\FillerDetector when ticket 14 gives
 * app:accuracy-check the full pipeline.
 */
final class FillerSoundCounter
{
    /**
     * @return list<string> every Filler Sound type, in lexicon order
     */
    public function fillerSoundTypes(): array
    {
        return array_keys(Lexicon::FILLER_SOUNDS);
    }

    /**
     * @param list<string> $words one entry per transcribed word, as the provider wrote it
     *
     * @return array<string, int> Filler Type => count, only types that occur, in lexicon order
     */
    public function count(array $words): array
    {
        $counts = array_fill_keys($this->fillerSoundTypes(), 0);

        foreach ($words as $word) {
            $type = Lexicon::fillerSoundType(Word::normaliseText($word));
            if (null !== $type) {
                ++$counts[$type];
            }
        }

        return array_filter($counts);
    }
}
