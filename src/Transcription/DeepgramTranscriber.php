<?php

declare(strict_types=1);

namespace App\Transcription;

use App\Processing\PermanentFailure;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Deepgram (DEEPGRAM_MODEL, Nova-2 per ADR 0001) with filler_words=true, through the Symfony AI
 * Platform's Deepgram bridge.
 */
#[AsAlias(Transcriber::class)]
final readonly class DeepgramTranscriber implements Transcriber
{
    public function __construct(
        #[Target('deepgram')]
        private PlatformInterface $deepgram,
        private DeepgramResponseNormaliser $normaliser,
        #[Autowire(env: 'DEEPGRAM_MODEL')]
        private string $model,
    ) {
    }

    public function transcribe(string $path, string $mimeType): Transcript
    {
        return $this->normaliser->normalise($this->listen($path, $mimeType));
    }

    /**
     * Deepgram's full /listen response, with errors already classified. Public for
     * app:accuracy-check, which saves the response as a fixture and can add options.
     *
     * @param array<string, string> $extraOptions e.g. ['smart_format' => 'true'] (accuracy check only)
     *
     * @return array<array-key, mixed>
     *
     * @throws PermanentFailure
     */
    public function listen(string $path, string $mimeType, array $extraOptions = []): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new PermanentFailure('We couldn\'t find the Recording for this Speech.', \sprintf('Recording "%s" is missing or unreadable.', $path));
        }

        // Built by hand, not Audio::fromFile(), which re-detects the type and ignores ours (SPEC §6.1).
        $audio = new Audio(static fn (): string => (string) file_get_contents($path), $mimeType, $path);

        $deferred = $this->deepgram->invoke($this->model, $audio, [
            'filler_words' => 'true',
            'punctuate' => 'true',
            'language' => 'en',
            ...$extraOptions,
        ]);

        // Convert first so HTTP errors surface as platform exceptions, then read the raw words (ADR 0001).
        try {
            $deferred->getResult();
        } catch (PlatformException $e) {
            throw $this->classify($e, $deferred);
        }

        return $deferred->getRawResult()->getData();
    }

    /**
     * 429 and 5xx are short-lived and rethrown as they are. Any other 4xx is permanent. The bridge
     * has no dedicated exception for 403 (or 402, 413…): it throws a plain RuntimeException, so
     * the status code is read from the raw response.
     */
    private function classify(PlatformException $e, DeferredResult $deferred): \Throwable
    {
        if ($e instanceof RetryableExceptionInterface) {
            return $e;
        }

        $raw = $deferred->getRawResult();
        $status = $raw instanceof RawHttpResult ? $raw->getObject()->getStatusCode() : 0;

        if ($status < 400 || $status >= 500 || 408 === $status || 429 === $status) {
            return $e;
        }

        $userMessage = match ($status) {
            401, 403 => 'The transcription service rejected our API key. Check DEEPGRAM_API_KEY, then press Retry.',
            402 => 'The transcription service account has run out of credit.',
            400, 413, 415 => 'The transcription service couldn\'t process this Recording.',
            default => 'The transcription service refused this Recording.',
        };

        return new PermanentFailure($userMessage, \sprintf('Deepgram returned HTTP %d: %s', $status, $e->getMessage()), $e);
    }
}
