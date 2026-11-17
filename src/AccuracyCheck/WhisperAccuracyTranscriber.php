<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

use App\Transcription\Transcript;
use App\Transcription\Word;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * OpenAI whisper-1 with word timestamps and a filler-rich prompt (ADR 0001's fallback).
 */
#[AsTaggedItem('whisper')]
final readonly class WhisperAccuracyTranscriber implements AccuracyTranscriber
{
    /** Whisper copies the prompt's style, so showing it fillers makes it more likely to keep them. */
    private const string PROMPT = 'Umm, let me think like, hmm... Okay, here\'s what I\'m, like, thinking. So, uh, I mean, er, you know, ah, it was, erm, kind of basically right.';

    public function __construct(
        #[Target('openai')]
        private PlatformInterface $openai,
    ) {
    }

    public function transcribe(AccuracyRecording $recording, array $providerOptions = []): RawTranscription
    {
        $path = $recording->audioPath;
        $audio = new Audio(static fn (): string => (string) file_get_contents($path), $recording->mimeType, $path);

        $deferred = $this->openai->invoke('whisper-1', $audio, [
            // Not the bridge's "verbose" flag: its converter requires "segments", which
            // word-only granularity leaves out, and OpenAI won't take two granularities here.
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'word',
            'language' => 'en',
            'prompt' => self::PROMPT,
            ...$providerOptions,
        ]);
        // No getResult(): in ai-platform 1.0.0-BETA1, PlatformSubscriber::processResult() wraps any
        // "response_format" option in the structured-output converter, which then json_decodes the
        // plain-text result and throws. Check the HTTP status by hand instead.
        $response = $deferred->getRawResult()->getObject();
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('OpenAI returned HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
        }
        $data = $response->toArray();

        // Whisper's word list carries no punctuation or confidence; the words are all we need.
        $words = array_map(
            static fn (array $word): Word => new Word(Word::normaliseText((string) $word['word']), (string) $word['word'], (float) $word['start'], (float) $word['end'], 1.0),
            $data['words'] ?? [],
        );

        return new RawTranscription(new Transcript($words, (float) ($data['duration'] ?? 0.0)), $data);
    }
}
