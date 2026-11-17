<?php

declare(strict_types=1);

namespace App\Speech;

use App\Entity\Speech;
use App\Message\ProcessSpeech;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creating and deleting Speeches: the work behind the upload and delete endpoints.
 */
final readonly class SpeechLifecycle
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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
