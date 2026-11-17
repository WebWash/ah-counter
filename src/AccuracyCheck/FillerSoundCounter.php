<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

/**
 * Counts Filler Sounds in a transcriber's raw words, by Filler Type (SPEC §4 lexicon).
 *
 * Stand-in for the transcription test (ticket 04) only: Filler Phrases need context and
 * timestamps, which is the job of Analysis\FillerDetector (ticket 10). Replace this with
 * FillerDetector when ticket 14 gives app:accuracy-check the full pipeline.
 */
final class FillerSoundCounter
{
    /**
     * Canonical Filler Type => pattern for it and its elongated spellings, in report order.
     * Deepgram's "mhmm" and "mm" map to "hmm". Hyphenated yes/no grunts ("uh-huh") never match.
     */
    private const array SOUNDS = [
        'um' => '/^u+m+$/',
        'uh' => '/^u+h+$/',
        'ah' => '/^a+h+$/',
        'er' => '/^e+r+$/',
        'erm' => '/^e+r+m+$/',
        'hmm' => '/^(h+m+|m+h+m+|m{2,})$/',
    ];

    /**
     * @return list<string> every Filler Sound type, in lexicon order
     */
    public function fillerSoundTypes(): array
    {
        return array_keys(self::SOUNDS);
    }

    /**
     * @param list<string> $words one entry per transcribed word, as the provider wrote it
     *
     * @return array<string, int> Filler Type => count, only types that occur, in lexicon order
     */
    public function count(array $words): array
    {
        $counts = array_fill_keys(array_keys(self::SOUNDS), 0);

        foreach ($words as $word) {
            $type = $this->fillerType($word);
            if (null !== $type) {
                ++$counts[$type];
            }
        }

        return array_filter($counts);
    }

    private function fillerType(string $word): ?string
    {
        // Lower-case and strip surrounding punctuation; keep inner hyphens so "uh-huh" stays one word.
        $normalised = trim(mb_strtolower($word), " \t\n\r\0\x0B.,!?;:\"'()[]…-");

        foreach (self::SOUNDS as $type => $pattern) {
            if (1 === preg_match($pattern, $normalised)) {
                return $type;
            }
        }

        return null;
    }
}
