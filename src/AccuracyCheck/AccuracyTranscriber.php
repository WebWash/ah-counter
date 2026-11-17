<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One transcription provider under test in app:accuracy-check (ticket 04).
 *
 * Each implementation registers itself under its --provider name with #[AsTaggedItem], so
 * dropping a provider that lost the comparison means deleting its class and its package.
 */
#[AutoconfigureTag(self::TAG)]
interface AccuracyTranscriber
{
    public const string TAG = 'app.accuracy_transcriber';

    /**
     * @param array<string, string> $providerOptions extra request options for this provider only
     */
    public function transcribe(AccuracyRecording $recording, array $providerOptions = []): RawTranscription;
}
