<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * The English Filler lexicon (SPEC §4). Words are compared after Word::normaliseText():
 * lower-case, surrounding punctuation removed, inner hyphens kept.
 */
final class Lexicon
{
    /**
     * Canonical Filler Sound => pattern for it and its elongated spellings. Deepgram writes "hmm" as
     * "mhmm" or "mm". With Deepgram, "ah" and "er" mostly arrive as "uh" (ADR 0001).
     */
    public const array FILLER_SOUNDS = [
        'um' => '/^u+m+$/',
        'uh' => '/^u+h+$/',
        'ah' => '/^a+h+$/',
        'er' => '/^e+r+$/',
        'erm' => '/^e+r+m+$/',
        'hmm' => '/^(h+m+|m+h+m+|m{2,})$/',
    ];

    /**
     * Deepgram's yes/no grunts. They carry meaning, so they are never Fillers. (The patterns above
     * already reject them; this list documents the rule and guards it.)
     */
    public const array NOT_FILLERS = ['uh-huh', 'uh-uh', 'mm-mm', 'nuh-uh'];

    /**
     * Filler Phrases, which become Candidates for the judge.
     */
    public const array FILLER_PHRASES = [
        'like',
        'so',
        'you know',
        'i mean',
        'sort of',
        'kind of',
        'basically',
        'actually',
        'right',
        'well',
    ];

    /**
     * The canonical Filler Sound type for a normalised word, or null.
     */
    public static function fillerSoundType(string $text): ?string
    {
        if (\in_array($text, self::NOT_FILLERS, true)) {
            return null;
        }

        foreach (self::FILLER_SOUNDS as $type => $pattern) {
            if (1 === preg_match($pattern, $text)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Filler Phrases split into words, longest first, so "you know" is tried before any one-word phrase.
     *
     * @return array<string, list<string>> phrase => its words
     */
    public static function phrasesLongestFirst(): array
    {
        $phrases = [];
        foreach (self::FILLER_PHRASES as $phrase) {
            $phrases[$phrase] = explode(' ', $phrase);
        }
        uasort($phrases, static fn (array $a, array $b): int => \count($b) <=> \count($a));

        return $phrases;
    }
}
