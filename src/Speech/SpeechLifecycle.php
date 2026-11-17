<?php

declare(strict_types=1);

namespace App\Speech;

use App\Entity\Speech;
use App\Message\ProcessSpeech;
use App\Repository\SpeechRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creating, retrying and deleting Speeches: the work behind the upload, retry and delete endpoints.
 */
final readonly class SpeechLifecycle
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpeechRepository $speechRepository,
        private RecordingStorage $recordingStorage,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Saves the Recording and a pending Speech, then queues processing.
     *
     * @throws UnsupportedRecording
     */
    public function create(UploadedFile $recording, float $clientDuration, ?string $title): Speech
    {
        $id = Uuid::v4();
        $stored = $this->recordingStorage->store($recording, $id, $recording->getClientMimeType());

        $title = trim($title ?? '');
        $speech = new Speech($id, $stored->filename, $stored->mimeType, $clientDuration, '' === $title ? null : $title);

        try {
            $this->entityManager->persist($speech);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->recordingStorage->delete($speech);

            throw $e;
        }

        $this->bus->dispatch(new ProcessSpeech($id->toRfc4122()));

        return $speech;
    }

    /**
     * Sends a failed Speech back to pending and queues it again. The conditional update makes a
     * double click harmless: only the request that changed the row dispatches (SPEC §5).
     *
     * @return bool false when the Speech was not failed (nothing dispatched)
     */
    public function retry(Speech $speech): bool
    {
        if (!$this->speechRepository->resetFailedForRetry($speech->getId())) {
            return false;
        }

        $this->bus->dispatch(new ProcessSpeech($speech->getId()->toRfc4122()));

        return true;
    }

    /**
     * Removes the row, then the Recording.
     *
     * @throws SpeechStillProcessing unless the Speech is done or failed
     */
    public function delete(Speech $speech): void
    {
        if (!$speech->getStatus()->isFinished()) {
            throw new SpeechStillProcessing($speech);
        }

        $this->entityManager->remove($speech);
        $this->entityManager->flush();
        $this->recordingStorage->delete($speech);
    }
}
