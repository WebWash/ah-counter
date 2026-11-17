<?php

declare(strict_types=1);

namespace App\Transcription;

/**
 * The word-by-word text of a Recording, keeping the filler sounds the speaker made.
 */
final readonly class Transcript
{
    /**
     * @param list<Word> $words in time order
     */
    public function __construct(
        public array $words,
        public float $durationSeconds,
    ) {
    }

    /**
     * The words in the shape stored in Speech::$transcript. The duration is stored separately,
     * in Speech::$durationSeconds.
     *
     * @return list<array{text: string, punctuated: string, start: float, end: float, confidence: float}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Word $word): array => $word->toArray(), $this->words);
    }

    /**
     * @param list<array<string, mixed>> $words as written by toArray()
     */
    public static function fromArray(array $words, float $durationSeconds): self
    {
        return new self(array_map(Word::fromArray(...), $words), $durationSeconds);
    }

    /**
     * The punctuated words joined into running text.
     */
    public function text(): string
    {
        return implode(' ', array_map(static fn (Word $word): string => $word->punctuated, $this->words));
    }
}
