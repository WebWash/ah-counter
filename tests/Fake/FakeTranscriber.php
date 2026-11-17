<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Transcription\Transcriber;
use App\Transcription\Transcript;
use App\Transcription\Word;

/**
 * Returns a fixed Transcript, or throws the queued exception, and counts its calls.
 */
final class FakeTranscriber implements Transcriber
{
    public int $calls = 0;

    /** @var list<\Throwable> thrown one per call, before any Transcript is returned */
    private array $failures = [];

    /** @var (\Closure(): void)|null */
    private ?\Closure $onCall = null;

    public function __construct(
        private Transcript $transcript,
    ) {
    }

    /**
     * "So I um think it was like huge", 20 s long: one Filler Sound and two Candidates.
     */
    public static function speaking(float $durationSeconds = 20.0): self
    {
        return new self(self::transcript(['So', 'I', 'um', 'think', 'it', 'was', 'like', 'huge.'], $durationSeconds));
    }

    /**
     * @param list<string> $spoken punctuated words, one second apart
     */
    public static function transcript(array $spoken, float $durationSeconds): Transcript
    {
        $words = [];
        foreach ($spoken as $i => $punctuated) {
            $words[] = new Word(Word::normaliseText($punctuated), $punctuated, (float) $i, $i + 0.5, 0.95);
        }

        return new Transcript($words, $durationSeconds);
    }

    public function failNextCallWith(\Throwable $failure): self
    {
        $this->failures[] = $failure;

        return $this;
    }

    /**
     * @param \Closure(): void $onCall runs at the start of every call
     */
    public function onCall(\Closure $onCall): self
    {
        $this->onCall = $onCall;

        return $this;
    }

    public function transcribe(string $path, string $mimeType): Transcript
    {
        ++$this->calls;
        if (null !== $this->onCall) {
            ($this->onCall)();
        }
        if ([] !== $this->failures) {
            throw array_shift($this->failures);
        }

        return $this->transcript;
    }
}
