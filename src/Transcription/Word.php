<?php

declare(strict_types=1);

namespace App\Transcription;

/**
 * One word of a Transcript and its position in time.
 */
final readonly class Word
{
    /**
     * @param string $text       lower-case, without surrounding punctuation: what Filler matching uses
     * @param string $punctuated the word as it should be displayed ("Uh,")
     */
    public function __construct(
        public string $text,
        public string $punctuated,
        public float $start,
        public float $end,
        public float $confidence,
    ) {
    }

    /**
     * Lower-case and strip leading and trailing punctuation, keeping inner hyphens and apostrophes
     * ("uh-huh", "it's").
     */
    public static function normaliseText(string $word): string
    {
        return (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', mb_strtolower($word));
    }

    /**
     * @return array{text: string, punctuated: string, start: float, end: float, confidence: float}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'punctuated' => $this->punctuated,
            'start' => $this->start,
            'end' => $this->end,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * @param array<string, mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['text'],
            (string) $data['punctuated'],
            (float) $data['start'],
            (float) $data['end'],
            (float) $data['confidence'],
        );
    }
}
