<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Repository\SpeechRepository;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

final class SpeechRepositoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RecreatesDatabase;

    private EntityManagerInterface $entityManager;
    private SpeechRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::recreateDatabase();
        $this->repository = self::getContainer()->get(SpeechRepository::class);
    }

    public function testResetFailedForRetryTurnsAFailedSpeechBackToPendingExactlyOnce(): void
    {
        $speech = $this->persist(new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0));
        $speech->markFailed('Deepgram is unavailable.');
        $this->entityManager->flush();

        self::assertTrue($this->repository->resetFailedForRetry($speech->getId()));
        self::assertFalse($this->repository->resetFailedForRetry($speech->getId()));

        $this->entityManager->clear();
        $reloaded = $this->repository->find($speech->getId());
        self::assertSame(SpeechStatus::Pending, $reloaded?->getStatus());
        self::assertNull($reloaded->getError());
    }

    public function testResetFailedForRetryLeavesASpeechThatIsNotFailedAlone(): void
    {
        $speech = $this->persist(new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0));
        $speech->startStep(SpeechStatus::Transcribing);
        $this->entityManager->flush();

        self::assertFalse($this->repository->resetFailedForRetry($speech->getId()));

        $this->entityManager->clear();
        self::assertSame(SpeechStatus::Transcribing, $this->repository->find($speech->getId())?->getStatus());
    }

    public function testResetFailedForRetryReturnsFalseForAnUnknownSpeech(): void
    {
        self::assertFalse($this->repository->resetFailedForRetry(Uuid::v4()));
    }

    public function testFindRecentReturnsTheNewestSpeechesFirstUpToTheLimit(): void
    {
        self::mockTime('2026-11-17 09:00:00');
        $this->persist(new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0, 'Oldest'));
        self::mockTime('2026-11-17 11:00:00');
        $this->persist(new Speech(Uuid::v4(), 'b.webm', 'audio/webm', 30.0, 'Newest'));
        self::mockTime('2026-11-17 10:00:00');
        $this->persist(new Speech(Uuid::v4(), 'c.webm', 'audio/webm', 30.0, 'Middle'));

        $titles = array_map(static fn (Speech $s) => $s->getTitle(), $this->repository->findRecent(2));

        self::assertSame(['Newest', 'Middle'], $titles);
    }

    private function persist(Speech $speech): Speech
    {
        $this->entityManager->persist($speech);
        $this->entityManager->flush();

        return $speech;
    }
}
