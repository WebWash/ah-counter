<?php

declare(strict_types=1);

namespace App\Tests\Processing;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Message\ProcessSpeech;
use App\Processing\PermanentFailure;
use App\Processing\SpeechFailureSubscriber;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

final class SpeechFailureSubscriberTest extends KernelTestCase
{
    use RecreatesDatabase;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::recreateDatabase();
    }

    public function testASpeechThatWillBeRetriedKeepsItsStatus(): void
    {
        $speech = $this->createSpeech(SpeechStatus::Transcribing);
        $event = $this->failedEvent($speech, new \RuntimeException('HTTP 503'));
        $event->setForRetry();

        $this->dispatch($event);

        $reloaded = $this->reload($speech);
        self::assertSame(SpeechStatus::Transcribing, $reloaded->getStatus());
        self::assertNull($reloaded->getError());
    }

    public function testAPermanentFailureMarksTheSpeechFailedWithItsUserMessage(): void
    {
        $speech = $this->createSpeech(SpeechStatus::Transcribing);
        $permanent = new PermanentFailure('We couldn\'t hear any speech in this recording.', 'The Transcriber returned no words.');

        $this->dispatch($this->failedEvent($speech, new UnrecoverableMessageHandlingException($permanent->getMessage(), 0, $permanent)));

        $reloaded = $this->reload($speech);
        self::assertSame(SpeechStatus::Failed, $reloaded->getStatus());
        self::assertSame('We couldn\'t hear any speech in this recording.', $reloaded->getError());
    }

    public function testRetriesRunningOutGivesAGenericMessageWithoutTheRawError(): void
    {
        $speech = $this->createSpeech(SpeechStatus::Analysing);

        $this->dispatch($this->failedEvent($speech, new \RuntimeException('SQLSTATE[HY000]: secret internals')));

        $reloaded = $this->reload($speech);
        self::assertSame(SpeechStatus::Failed, $reloaded->getStatus());
        self::assertSame(SpeechFailureSubscriber::GENERIC_MESSAGE, $reloaded->getError());
    }

    public function testUnsavedChangesFromTheFailedStepAreDiscarded(): void
    {
        $speech = $this->createSpeech(SpeechStatus::Analysing);
        $speech->recordFillers([]); // the step got this far in memory, then failed before flushing

        $this->dispatch($this->failedEvent($speech, new \RuntimeException('boom')));

        self::assertNull($this->reload($speech)->getFillers());
    }

    public function testAFailureForADeletedSpeechIsIgnored(): void
    {
        $envelope = new Envelope(new ProcessSpeech(Uuid::v4()->toRfc4122()));

        $this->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [new \RuntimeException('boom')])));

        $this->expectNotToPerformAssertions();
    }

    public function testRunsAfterMessengerHasDecidedWhetherToRetry(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $priorities = [];
        foreach ($dispatcher->getListeners(WorkerMessageFailedEvent::class) as $listener) {
            if (\is_array($listener)) {
                $priorities[$listener[0]::class] = $dispatcher->getListenerPriority(WorkerMessageFailedEvent::class, $listener);
            }
        }

        self::assertArrayHasKey(SpeechFailureSubscriber::class, $priorities);
        self::assertLessThan($priorities[SendFailedMessageForRetryListener::class], $priorities[SpeechFailureSubscriber::class]);
        self::assertLessThan(100, $priorities[SpeechFailureSubscriber::class]);
    }

    private function createSpeech(SpeechStatus $step): Speech
    {
        $speech = new Speech(Uuid::v4(), 'speech.webm', 'audio/webm', 20.0);
        $speech->startStep($step);
        $this->entityManager->persist($speech);
        $this->entityManager->flush();

        return $speech;
    }

    private function failedEvent(Speech $speech, \Throwable $handlerError): WorkerMessageFailedEvent
    {
        $envelope = new Envelope(new ProcessSpeech($speech->getId()->toRfc4122()));

        return new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [$handlerError]));
    }

    private function dispatch(WorkerMessageFailedEvent $event): void
    {
        self::getContainer()->get(SpeechFailureSubscriber::class)->onMessageFailed($event);
    }

    private function reload(Speech $speech): Speech
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Speech::class, $speech->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
