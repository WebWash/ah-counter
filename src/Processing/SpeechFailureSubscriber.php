<?php

declare(strict_types=1);

namespace App\Processing;

use App\Entity\Speech;
use App\Message\ProcessSpeech;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Uid\Uuid;

/**
 * Marks a Speech failed once Messenger has given up on it (SPEC §5).
 *
 * Priority -10: after SendFailedMessageForRetryListener (100) has decided willRetry(), and after
 * Doctrine's worker subscriber (0) has cleared the identity map. The raw exception is logged;
 * the speaker sees a PermanentFailure's user message, or a generic one.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onMessageFailed', priority: -10)]
final readonly class SpeechFailureSubscriber
{
    public const string GENERIC_MESSAGE = 'Something went wrong while analysing this Speech. Please try again in a few minutes.';

    public function __construct(
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ProcessSpeech || $event->willRetry() || !Uuid::isValid($message->speechId)) {
            return;
        }

        $error = $event->getThrowable();
        $this->logger->error('Processing Speech {speechId} failed for good: {error}', [
            'speechId' => $message->speechId,
            'error' => $error->getMessage(),
            'exception' => $error,
        ]);

        $entityManager = $this->freshEntityManager();
        $speech = $entityManager->find(Speech::class, Uuid::fromString($message->speechId));
        if (null === $speech || $speech->getStatus()->isFinished()) {
            return;
        }

        $speech->markFailed($this->userMessage($error));
        $entityManager->flush();
    }

    /**
     * An empty identity map, so nothing the failed step left unsaved in memory gets flushed now.
     * A failed flush closes the EntityManager, so it may need resetting first.
     */
    private function freshEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->registry->getManagerForClass(Speech::class);
        \assert($entityManager instanceof EntityManagerInterface);

        if (!$entityManager->isOpen()) {
            $this->registry->resetManager();
            $entityManager = $this->registry->getManagerForClass(Speech::class);
            \assert($entityManager instanceof EntityManagerInterface);
        }

        $entityManager->clear();

        return $entityManager;
    }

    private function userMessage(\Throwable $error): string
    {
        // Handler errors arrive wrapped (HandlerFailedException → Unrecoverable… → PermanentFailure).
        $pending = [$error];
        while (null !== $current = array_shift($pending)) {
            if ($current instanceof PermanentFailure) {
                return $current->userMessage;
            }
            if ($current instanceof HandlerFailedException) {
                array_push($pending, ...$current->getWrappedExceptions());
            }
            if (null !== $current->getPrevious()) {
                $pending[] = $current->getPrevious();
            }
        }

        return self::GENERIC_MESSAGE;
    }
}
