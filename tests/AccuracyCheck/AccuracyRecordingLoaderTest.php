<?php

declare(strict_types=1);

namespace App\Tests\AccuracyCheck;

use App\AccuracyCheck\AccuracyRecordingLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AccuracyRecordingLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ah-counter-recordings-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testPairsEachRecordingWithItsHandCountedFillersInNaturalOrder(): void
    {
        $this->recording('clip-10', 'webm', '{"um": 1}');
        $this->recording('clip-2', 'webm', '{"UM": 4, "uh": 6, "You Know": 2}');
        $this->recording('clip-1', 'mp4', '{}');

        $recordings = (new AccuracyRecordingLoader())->load($this->dir);

        self::assertSame(['clip-1', 'clip-2', 'clip-10'], array_map(static fn ($recording) => $recording->name, $recordings));

        self::assertSame($this->dir.'/clip-2.webm', $recordings[1]->audioPath);
        self::assertSame('audio/webm', $recordings[1]->mimeType);
        self::assertSame(['um' => 4, 'uh' => 6, 'you know' => 2], $recordings[1]->expected);

        self::assertSame('audio/mp4', $recordings[0]->mimeType);
        self::assertSame([], $recordings[0]->expected);
    }

    public function testReturnsNoRecordingsForAnEmptyDirectory(): void
    {
        self::assertSame([], (new AccuracyRecordingLoader())->load($this->dir));
    }

    public function testLoadsARecordingThatHasNotBeenHandCountedYet(): void
    {
        file_put_contents($this->dir.'/clip-1.webm', 'audio');

        $recordings = (new AccuracyRecordingLoader())->load($this->dir);

        self::assertCount(1, $recordings);
        self::assertSame('clip-1', $recordings[0]->name);
        self::assertNull($recordings[0]->expected);
    }

    public function testRejectsAHandCountWithoutARecording(): void
    {
        file_put_contents($this->dir.'/clip-1.json', '{"um": 1}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clip-1.json has no recording');

        (new AccuracyRecordingLoader())->load($this->dir);
    }

    public function testRejectsAHandCountThatIsNotAnObjectOfWholeNumbers(): void
    {
        $this->recording('clip-1', 'webm', '{"um": "four"}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clip-1.json');

        (new AccuracyRecordingLoader())->load($this->dir);
    }

    public function testRejectsAMissingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AccuracyRecordingLoader())->load($this->dir.'/nope');
    }

    private function recording(string $name, string $extension, string $json): void
    {
        file_put_contents(\sprintf('%s/%s.%s', $this->dir, $name, $extension), 'audio');
        file_put_contents(\sprintf('%s/%s.json', $this->dir, $name), $json);
    }
}
