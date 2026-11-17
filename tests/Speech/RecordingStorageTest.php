<?php

declare(strict_types=1);

namespace App\Tests\Speech;

use App\Entity\Speech;
use App\Speech\RecordingStorage;
use App\Speech\UnsupportedRecording;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * The audio-type table of SPEC §6.8, checked against small real files made with ffmpeg
 * (tests/Fixtures/audio/). finfo reports tone.webm as video/webm, tone.mp4 as video/mp4,
 * tone.m4a as audio/x-m4a and tone.ogg as audio/ogg.
 */
final class RecordingStorageTest extends KernelTestCase
{
    private RecordingStorage $storage;
    private string $recordingsDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->recordingsDir = self::getContainer()->getParameter('app.recordings_dir');
        $this->storage = new RecordingStorage($this->recordingsDir);
        (new Filesystem())->remove($this->recordingsDir);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function acceptedRecordings(): iterable
    {
        yield 'Chrome webm with codec suffix' => ['tone.webm', 'audio/webm;codecs=opus', 'audio/webm', 'webm'];
        yield 'webm without codec suffix' => ['tone.webm', 'audio/webm', 'audio/webm', 'webm'];
        yield 'Firefox ogg with codec suffix' => ['tone.ogg', 'audio/ogg; codecs=opus', 'audio/ogg', 'ogg'];
        yield 'Safari mp4 detected as video/mp4' => ['tone.mp4', 'audio/mp4', 'audio/mp4', 'mp4'];
        yield 'mp4 detected as audio/x-m4a, with codec suffix' => ['tone.m4a', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'mp4'];
        yield 'upper-case browser type' => ['tone.webm', 'Audio/WebM;codecs=opus', 'audio/webm', 'webm'];
    }

    #[DataProvider('acceptedRecordings')]
    public function testItStoresEveryRowOfTheAudioTypeTable(string $fixture, string $browserType, string $expectedMimeType, string $expectedExtension): void
    {
        $id = Uuid::v4();

        $stored = $this->storage->store($this->upload($fixture), $id, $browserType);

        self::assertSame($id->toRfc4122().'.'.$expectedExtension, $stored->filename);
        self::assertSame($expectedMimeType, $stored->mimeType);
        self::assertFileEquals(__DIR__.'/../Fixtures/audio/'.$fixture, $this->recordingsDir.'/'.$stored->filename);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedRecordings(): iterable
    {
        yield 'webm sent as mp4' => ['tone.webm', 'audio/mp4'];
        yield 'mp4 sent as webm' => ['tone.mp4', 'audio/webm'];
        yield 'ogg sent as webm' => ['tone.ogg', 'audio/webm;codecs=opus'];
        yield 'browser type outside the table' => ['tone.webm', 'video/webm'];
        yield 'not audio at all' => ['not-audio.txt', 'audio/webm'];
        yield 'empty browser type' => ['tone.webm', ''];
    }

    #[DataProvider('rejectedRecordings')]
    public function testItRejectsAFileWhoseContainerDoesNotMatchTheBrowserType(string $fixture, string $browserType): void
    {
        try {
            $this->storage->store($this->upload($fixture), Uuid::v4(), $browserType);
            self::fail('Expected the Recording to be rejected.');
        } catch (UnsupportedRecording) {
            self::assertDirectoryDoesNotExist($this->recordingsDir);
        }
    }

    public function testPathPointsAtTheStoredFileAndDeleteRemovesIt(): void
    {
        $id = Uuid::v4();
        $stored = $this->storage->store($this->upload('tone.webm'), $id, 'audio/webm');
        $speech = new Speech($id, $stored->filename, $stored->mimeType, 12.0);

        $path = $this->storage->path($speech);
        self::assertFileExists($path);
        self::assertSame($this->recordingsDir.'/'.$stored->filename, $path);

        $this->storage->delete($speech);

        self::assertFileDoesNotExist($path);
    }

    public function testDeletingAMissingFileIsHarmless(): void
    {
        $speech = new Speech(Uuid::v4(), 'gone.webm', 'audio/webm', 12.0);

        $this->storage->delete($speech);

        self::assertFileDoesNotExist($this->storage->path($speech));
    }

    /**
     * A copy, because storing moves the file and the client's filename must not matter.
     */
    private function upload(string $fixture): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'ah-upload-');
        copy(__DIR__.'/../Fixtures/audio/'.$fixture, $copy);

        return new UploadedFile($copy, '../../evil name.exe', 'application/octet-stream', null, true);
    }
}
