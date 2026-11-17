<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Deepgram (DEEPGRAM_MODEL, Nova-2 per ADR 0001) with filler_words=true, the way SPEC §6.1 calls it.
 */
#[AsTaggedItem('deepgram')]
final readonly class DeepgramAccuracyTranscriber implements AccuracyTranscriber
{
    public function __construct(
        #[Target('deepgram')]
        private PlatformInterface $deepgram,
        #[Autowire(env: 'DEEPGRAM_MODEL')]
        private string $model,
    ) {
    }

    public function transcribe(AccuracyRecording $recording, array $providerOptions = []): RawTranscription
    {
        $path = $recording->audioPath;
        // Built by hand, not Audio::fromFile(), so the Recording's own MIME type is sent (SPEC §6.1).
        $audio = new Audio(static fn (): string => (string) file_get_contents($path), $recording->mimeType, $path);

        $deferred = $this->deepgram->invoke($this->model, $audio, [
            'filler_words' => 'true',
            'punctuate' => 'true',
            'language' => 'en',
            ...$providerOptions,
        ]);
        // Convert first so HTTP errors surface as platform exceptions, then read the raw words (ADR 0001).
        $deferred->getResult();
        $data = $deferred->getRawResult()->getData();

        $alternative = $data['results']['channels'][0]['alternatives'][0] ?? [];

        return new RawTranscription(
            array_map(static fn (array $word): string => (string) $word['word'], $alternative['words'] ?? []),
            (string) ($alternative['transcript'] ?? ''),
            $data,
        );
    }
}
