<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessSpeech;
use App\Processing\PermanentFailure;
use App\Processing\SpeechProcessor;
use App\Repository\SpeechRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Reloads the Speech and runs the pipeline (SPEC §5).
 *
 * A deleted Speech, or one already done or failed (a duplicate delivery), is left alone. Permanent
 * failures become UnrecoverableMessageHandlingException so Messenger doesn't retry them; anything
 * else is rethrown as it is and retried up to max_retries. RecoverableMessageHandlingException is
 * never used: it ignores max_retries.
 */
#[AsMessageHandler]
final readonly class ProcessSpeechHandler
{
    public function __construct(
        private SpeechRepository $speechRepository,
        private SpeechProcessor $speechProcessor,
    ) {
    }

    public function __invoke(ProcessSpeech $message): void
    {
        if (!Uuid::isValid($message->speechId)) {
            return;
        }

        $speech = $this->speechRepository->find(Uuid::fromString($message->speechId));
        if (null === $speech || $speech->getStatus()->isFinished()) {
            return;
        }

        try {
            $this->speechProcessor->process($speech);
        } catch (PermanentFailure $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }
}
