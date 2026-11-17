<?php

declare(strict_types=1);

namespace App\Transcription;

/**
 * Turns Deepgram's full /listen response into a Transcript (SPEC §6.1, ADR 0001).
 *
 * Reads the per-word data from results.channels[0].alternatives[0].words and the duration from
 * metadata.duration: the bridge's plain-text result has neither.
 */
final class DeepgramResponseNormaliser
{
    /**
     * @param array<array-key, mixed> $response
     *
     * @throws MalformedTranscription when an expected key is missing or has the wrong type
     */
    public function normalise(array $response): Transcript
    {
        $duration = $response['metadata']['duration'] ?? null;
        if (!\is_int($duration) && !\is_float($duration)) {
            throw new MalformedTranscription('Deepgram response has no numeric "metadata.duration".');
        }

        $words = $response['results']['channels'][0]['alternatives'][0]['words'] ?? null;
        if (!\is_array($words) || !array_is_list($words)) {
            throw new MalformedTranscription('Deepgram response has no "results.channels[0].alternatives[0].words" list.');
        }

        return new Transcript(array_map($this->word(...), $words, array_keys($words)), (float) $duration);
    }

    private function word(mixed $word, int $index): Word
    {
        if (!\is_array($word) || !\is_string($word['word'] ?? null) || !is_numeric($word['start'] ?? null) || !is_numeric($word['end'] ?? null)) {
            throw new MalformedTranscription(\sprintf('Deepgram word #%d needs a "word", "start" and "end".', $index));
        }

        $punctuated = \is_string($word['punctuated_word'] ?? null) ? $word['punctuated_word'] : $word['word'];

        return new Word(
            Word::normaliseText($word['word']),
            $punctuated,
            (float) $word['start'],
            (float) $word['end'],
            is_numeric($word['confidence'] ?? null) ? (float) $word['confidence'] : 0.0,
        );
    }
}
