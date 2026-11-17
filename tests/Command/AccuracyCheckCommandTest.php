<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\AccuracyCheck\AccuracyRecording;
use App\AccuracyCheck\AccuracyTranscriber;
use App\AccuracyCheck\AccuracyRecordingLoader;
use App\AccuracyCheck\FillerSoundCounter;
use App\AccuracyCheck\RawTranscription;
use App\Command\AccuracyCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;

final class AccuracyCheckCommandTest extends TestCase
{
    private string $projectDir;

    /** @var list<array{recording: string, options: array<string, string>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/ah-counter-accuracy-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/tests/accuracy', recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testPrintsExpectedVersusDetectedFillerSoundsForEveryRecording(): void
    {
        $this->recording('clip-1', ['um' => 4, 'uh' => 2, 'like' => 3]);
        $this->recording('clip-2', []);

        $tester = $this->tester([
            'clip-1' => ['Um,', 'so', 'um', 'I', 'uh', 'like', 'uh', 'uh', 'mhmm', 'it'],
            'clip-2' => ['hello', 'everyone'],
        ]);
        $exit = $tester->execute(['--provider' => 'deepgram']);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit, $display);
        self::assertSame(['clip-1', 'clip-2'], array_column($this->calls, 'recording'));

        self::assertMatchesRegularExpression('/clip-1/', $display);
        self::assertMatchesRegularExpression('/\bum\s+4\s+2\s+-2\b/', $display);
        self::assertMatchesRegularExpression('/\buh\s+2\s+3\s+\+1\b/', $display);
        self::assertMatchesRegularExpression('/\bhmm\s+0\s+1\s+\+1\b/', $display);
        self::assertMatchesRegularExpression('/\blike\s+3\s+n\/a\b/', $display, 'Filler Phrases are not counted by this check');
        self::assertMatchesRegularExpression('/All Filler Sounds\s+6\s+6\s+0\b/', $display);
        self::assertMatchesRegularExpression('/All Filler Sounds\s+0\s+0\s+0\b/', $display);
    }

    public function testShowsDetectedFillerSoundsForARecordingThatHasNotBeenHandCounted(): void
    {
        file_put_contents($this->projectDir.'/tests/accuracy/clip-1.webm', 'audio');

        $tester = $this->tester(['clip-1' => ['um', 'so', 'uh', 'um']]);
        $exit = $tester->execute(['--provider' => 'deepgram']);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit, $display);
        self::assertMatchesRegularExpression('/\bum\s+\?\s+2\s*$/m', $display);
        self::assertMatchesRegularExpression('/All Filler Sounds\s+\?\s+3\s*$/m', $display);
    }

    public function testPassesSmartFormatToTheProvider(): void
    {
        $this->recording('clip-1', ['um' => 1]);

        $tester = $this->tester(['clip-1' => ['um']]);
        $tester->execute(['--provider' => 'deepgram', '--smart-format' => true]);

        self::assertSame(['smart_format' => 'true'], $this->calls[0]['options']);
    }

    public function testRefusesToSaveFixturesMadeWithSmartFormat(): void
    {
        $this->recording('clip-1', ['um' => 1]);

        $tester = $this->tester(['clip-1' => ['um']]);
        $exit = $tester->execute(['--provider' => 'deepgram', '--smart-format' => true, '--save-fixtures' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('production options', $tester->getDisplay());
        self::assertSame([], $this->calls);
        self::assertDirectoryDoesNotExist($this->projectDir.'/tests/Fixtures');
    }

    public function testSavesEachDeepgramResponseAsAFixture(): void
    {
        $this->recording('clip-1', ['um' => 1]);

        $tester = $this->tester(['clip-1' => ['um']]);
        $exit = $tester->execute(['--provider' => 'deepgram', '--save-fixtures' => true]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $fixture = $this->projectDir.'/tests/Fixtures/deepgram/clip-1.json';
        self::assertFileExists($fixture);
        self::assertSame(['provider' => 'deepgram', 'recording' => 'clip-1'], json_decode((string) file_get_contents($fixture), true));
    }

    public function testRefusesAnUnknownProviderAndListsTheAvailableOnes(): void
    {
        $this->recording('clip-1', ['um' => 1]);

        $tester = $this->tester(['clip-1' => ['um']]);
        $exit = $tester->execute(['--provider' => 'scribe']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('deepgram', $tester->getDisplay());
        self::assertSame([], $this->calls);
    }

    public function testExplainsWhereToSaveRecordingsWhenThereAreNone(): void
    {
        $tester = $this->tester([]);
        $exit = $tester->execute(['--provider' => 'deepgram']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('clip-1.webm', $tester->getDisplay());
    }

    public function testReportsAProviderFailureAndCarriesOnWithTheOtherRecordings(): void
    {
        $this->recording('clip-1', ['um' => 1]);
        $this->recording('clip-2', ['um' => 1]);

        $tester = $this->tester(['clip-2' => ['um']], failOn: 'clip-1');
        $exit = $tester->execute(['--provider' => 'deepgram']);

        $display = $tester->getDisplay();
        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid credentials', $display);
        self::assertMatchesRegularExpression('/All Filler Sounds\s+1\s+1\s+0\b/', $display);
    }

    /**
     * @param array<string, int> $expected
     */
    private function recording(string $name, array $expected): void
    {
        file_put_contents($this->projectDir.'/tests/accuracy/'.$name.'.webm', 'audio');
        file_put_contents($this->projectDir.'/tests/accuracy/'.$name.'.json', json_encode((object) $expected));
    }

    /**
     * @param array<string, list<string>> $wordsByRecording
     */
    private function tester(array $wordsByRecording, ?string $failOn = null): CommandTester
    {
        $calls = &$this->calls;
        $fake = new class($wordsByRecording, $failOn, $calls) implements AccuracyTranscriber {
            /**
             * @param array<string, list<string>>                                  $wordsByRecording
             * @param list<array{recording: string, options: array<string, string>}> $calls
             */
            public function __construct(private array $wordsByRecording, private ?string $failOn, private array &$calls)
            {
            }

            public function transcribe(AccuracyRecording $recording, array $providerOptions = []): RawTranscription
            {
                $this->calls[] = ['recording' => $recording->name, 'options' => $providerOptions];
                if ($recording->name === $this->failOn) {
                    throw new AuthenticationException('Invalid credentials.');
                }
                $words = $this->wordsByRecording[$recording->name];

                return new RawTranscription($words, implode(' ', $words), ['provider' => 'deepgram', 'recording' => $recording->name]);
            }
        };

        return new CommandTester(new AccuracyCheckCommand(
            new AccuracyRecordingLoader(),
            new FillerSoundCounter(),
            new ServiceLocator(['deepgram' => static fn () => $fake]),
            $this->projectDir,
            new Filesystem(),
        ));
    }
}
