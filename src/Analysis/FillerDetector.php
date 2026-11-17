<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Transcription\Transcript;
use App\Transcription\Word;

/**
 * Finds every Filler Sound and Candidate in a Transcript (SPEC §6.2). Plain code, no AI.
 *
 * Filler Sounds get verdict "filler". Filler Phrases are Candidates with verdict "unjudged",
 * for the judge to decide. Multi-word phrases match consecutive words, longest match first,
 * and occurrences never overlap.
 */
final class FillerDetector
{
    /**
     * @return list<FillerOccurrence> in time order
     */
    public function detect(Transcript $transcript): array
    {
        $texts = array_map(static fn (Word $word): string => Word::normaliseText($word->text), $transcript->words);
        $phrases = Lexicon::phrasesLongestFirst();
        $occurrences = [];
        $count = \count($texts);

        for ($i = 0; $i < $count;) {
            $soundType = Lexicon::fillerSoundType($texts[$i]);
            if (null !== $soundType) {
                $occurrences[] = $this->occurrence($transcript, $soundType, FillerKind::Sound, $i, $i, Verdict::Filler);
                ++$i;

                continue;
            }

            $matched = $this->matchPhrase($texts, $i, $phrases);
            if (null !== $matched) {
                $last = $i + \count($phrases[$matched]) - 1;
                $occurrences[] = $this->occurrence($transcript, $matched, FillerKind::Phrase, $i, $last, Verdict::Unjudged);
                $i = $last + 1;

                continue;
            }

            ++$i;
        }

        return $occurrences;
    }

    /**
     * @param list<string>                $texts
     * @param array<string, list<string>> $phrases longest first
     */
    private function matchPhrase(array $texts, int $at, array $phrases): ?string
    {
        foreach ($phrases as $phrase => $phraseWords) {
            if (\array_slice($texts, $at, \count($phraseWords)) === $phraseWords) {
                return $phrase;
            }
        }

        return null;
    }

    private function occurrence(Transcript $transcript, string $type, FillerKind $kind, int $first, int $last, Verdict $verdict): FillerOccurrence
    {
        return new FillerOccurrence(
            $type,
            $kind,
            $transcript->words[$first]->start,
            $transcript->words[$last]->end,
            $first,
            $last,
            $verdict,
        );
    }
}
