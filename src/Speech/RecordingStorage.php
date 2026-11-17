<?php

declare(strict_types=1);

namespace App\Speech;

use App\Entity\Speech;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps Recordings in app.recordings_dir (outside public/) as {uuid}.{ext}. The client's
 * filename is never used (SPEC §6.8).
 */
final readonly class RecordingStorage
{
    public function __construct(
        #[Autowire('%app.recordings_dir%')]
        private string $recordingsDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @throws UnsupportedRecording when the detected container isn't in the table or doesn't match the browser's type
     */
    public function store(UploadedFile $file, Uuid $id, string $browserMimeType): StoredRecording
    {
        $format = AudioFormat::match($file->getPathname(), $browserMimeType) ?? throw new UnsupportedRecording();

        $filename = $id->toRfc4122().'.'.$format->extension();
        $file->move($this->recordingsDir, $filename);

        return new StoredRecording($filename, $format->mimeType());
    }

    public function path(Speech $speech): string
    {
        return $this->recordingsDir.'/'.$speech->getRecordingFilename();
    }

    public function delete(Speech $speech): void
    {
        $this->filesystem->remove($this->path($speech));
    }
}
