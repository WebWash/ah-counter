<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

use App\Transcription\DeepgramResponseNormaliser;
use App\Transcription\DeepgramTranscriber;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Deepgram through the app's own DeepgramTranscriber (ticket 09), so the accuracy check measures
 * exactly what the pipeline sends and normalises.
 */
#[AsTaggedItem('deepgram')]
final readonly class DeepgramAccuracyTranscriber implements AccuracyTranscriber
{
    public function __construct(
        private DeepgramTranscriber $transcriber,
        private DeepgramResponseNormaliser $normaliser,
    ) {
    }

    public function transcribe(AccuracyRecording $recording, array $providerOptions = []): RawTranscription
    {
        $response = $this->transcriber->listen($recording->audioPath, $recording->mimeType, $providerOptions);
        return new RawTranscription($this->normaliser->normalise($response), $response);
    }
}
