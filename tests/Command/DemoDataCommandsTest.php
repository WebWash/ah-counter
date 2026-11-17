<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Coaching\Coaching;
use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Mcp\SpeechTools;
use App\Report\ReportBuilder;
use App\Repository\SpeechRepository;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * app:export-demo and app:load-demo with synthetic Speeches (SPEC §11, ticket 19). The real demo
 * Speeches in demo/ are recorded by the owner; these tests use var/test-demo.
 */
final class DemoDataCommandsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RecreatesDatabase;

    private const string BASELINE_ID = '0b5ae2d6-5f43-4f0c-9d43-3c1c4f6a0001';
    private const string IMPROVED_ID = '0b5ae2d6-5f43-4f0c-9d43-3c1c4f6a0002';

    private EntityManagerInterface $entityManager;
    private Filesystem $filesystem;
    private string $demoDir;
    private string $recordingsDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::recreateDatabase();
        $this->filesystem = new Filesystem();
        $this->demoDir = (string) self::getContainer()->getParameter('app.demo_dir');
        $this->recordingsDir = (string) self::getContainer()->getParameter('app.recordings_dir');
        $this->filesystem->remove($this->demoDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->demoDir);
        $this->filesystem->remove([$this->recordingsDir.'/'.self::BASELINE_ID.'.webm', $this->recordingsDir.'/'.self::IMPROVED_ID.'.mp4']);

        parent::tearDown();
    }

    public function testExportWritesTheRecordingAndEverySpeechFieldWithItsId(): void
    {
        $speech = $this->createBaseline();

        $tester = $this->command('app:export-demo');
        $exit = $tester->execute(['id' => self::BASELINE_ID, 'name' => 'week-1']);

        self::assertSame(Command::SUCCESS, $exit, $this->display($tester));
        self::assertFileEquals($this->recordingsDir.'/'.self::BASELINE_ID.'.webm', $this->demoDir.'/week-1.webm');

        $json = json_decode((string) file_get_contents($this->demoDir.'/week-1.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(self::BASELINE_ID, $json['id']);
        self::assertSame('Week 1: my first speech', $json['title']);
        self::assertSame('2026-11-18T19:30:00+00:00', $json['createdAt']);
        self::assertSame('done', $json['status']);
        self::assertSame('week-1.webm', $json['recording']);
        self::assertSame(self::BASELINE_ID.'.webm', $json['recordingFilename']);
        self::assertSame('audio/webm', $json['recordingMimeType']);
        self::assertSame(60.0, (float) $json['durationSeconds']);
        self::assertSame($speech->getTranscript(), $json['transcript']);
        self::assertSame($speech->getFillers(), $json['fillers']);
        self::assertSame($speech->getCoaching(), $json['coaching']);
        self::assertNull($json['error']);
        self::assertArrayHasKey('updatedAt', $json);
        self::assertStringContainsString('demo/week-1.json', str_replace($this->demoDir, 'demo', $this->display($tester)));
    }

    public function testExportNeedsADoneSpeech(): void
    {
        $speech = new Speech(Uuid::fromString(self::BASELINE_ID), self::BASELINE_ID.'.webm', 'audio/webm', 60.0);
        $speech->startStep(SpeechStatus::Transcribing);
        $this->entityManager->persist($speech);
        $this->entityManager->flush();

        $tester = $this->command('app:export-demo');

        self::assertSame(Command::FAILURE, $tester->execute(['id' => self::BASELINE_ID, 'name' => 'week-1']));
        self::assertStringContainsString('is still transcribing', $this->display($tester));
        self::assertDirectoryDoesNotExist($this->demoDir);
    }

    public function testExportNeedsASpeechWithCoaching(): void
    {
        $speech = $this->createBaseline();
        // Speeches processed before AI Coaching (ticket 15) stored an empty placeholder.
        $this->entityManager->getConnection()->executeStatement('UPDATE speech SET coaching = ? WHERE id = ?', ['[]', $speech->getId()->toBinary()]);
        $this->entityManager->clear();

        $tester = $this->command('app:export-demo');

        self::assertSame(Command::FAILURE, $tester->execute(['id' => self::BASELINE_ID, 'name' => 'week-1']));
        self::assertStringContainsString('has no Coaching', $this->display($tester));
        self::assertDirectoryDoesNotExist($this->demoDir);
    }

    public function testExportRejectsAnUnknownIdAndAnUnsafeName(): void
    {
        $this->createBaseline();
        $tester = $this->command('app:export-demo');

        self::assertSame(Command::FAILURE, $tester->execute(['id' => Uuid::v4()->toRfc4122(), 'name' => 'week-1']));
        self::assertStringContainsString('No Speech with ID', $this->display($tester));

        self::assertSame(Command::INVALID, $tester->execute(['id' => self::BASELINE_ID, 'name' => '../escape']));
        self::assertStringContainsString('lower-case letters, digits and hyphens', $this->display($tester));
        self::assertDirectoryDoesNotExist($this->demoDir);
    }

    public function testExportDoesNotOverwriteWithoutForce(): void
    {
        $this->createBaseline();
        $tester = $this->command('app:export-demo');
        $tester->execute(['id' => self::BASELINE_ID, 'name' => 'week-1']);

        self::assertSame(Command::FAILURE, $tester->execute(['id' => self::BASELINE_ID, 'name' => 'week-1']));
        self::assertStringContainsString('--force', $this->display($tester));
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => self::BASELINE_ID, 'name' => 'week-1', '--force' => true]));
    }

    public function testLoadCreatesTheDemoSpeechesDoneWithTheirReportsAndRecordings(): void
    {
        $this->exportBothThenEmptyTheDatabase();

        $tester = $this->command('app:load-demo');
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, $this->display($tester));
        $display = $this->display($tester);
        self::assertStringContainsString('/speeches/'.self::BASELINE_ID, $display);
        self::assertStringContainsString('/speeches/'.self::IMPROVED_ID, $display);

        $this->entityManager->clear();
        $speeches = self::getContainer()->get(SpeechRepository::class)->findAll();
        self::assertCount(2, $speeches);

        $baseline = $this->find(self::BASELINE_ID);
        self::assertSame(SpeechStatus::Done, $baseline->getStatus());
        self::assertSame('Week 1: my first speech', $baseline->getTitle());
        self::assertEquals(new \DateTimeImmutable('2026-11-18 19:30:00'), $baseline->getCreatedAt());
        self::assertFileExists($this->recordingsDir.'/'.self::BASELINE_ID.'.webm');

        $reports = self::getContainer()->get(ReportBuilder::class);
        $report = $reports->build($baseline);
        self::assertSame(60.0, $report->durationSeconds);
        self::assertSame(4, $report->totalFillers);
        self::assertSame(['um' => 3, 'like' => 1], $report->byType);
        self::assertSame('Plenty of "um": 3 in a minute.', $report->coaching?->summary);

        $improved = $this->find(self::IMPROVED_ID);
        self::assertSame('audio/mp4', $improved->getRecordingMimeType());
        self::assertFileExists($this->recordingsDir.'/'.self::IMPROVED_ID.'.mp4');
        self::assertSame(['um' => 1], $reports->build($improved)->byType);
    }

    public function testLoadingTwiceCreatesNoDuplicates(): void
    {
        $this->exportBothThenEmptyTheDatabase();
        $tester = $this->command('app:load-demo');
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('already loaded', $this->display($tester));
        self::assertStringContainsString('/speeches/'.self::BASELINE_ID, $this->display($tester));
        $this->entityManager->clear();
        self::assertCount(2, self::getContainer()->get(SpeechRepository::class)->findAll());
    }

    public function testCompareSpeechesWorksOnTheDemoSpeechesWithoutApiKeys(): void
    {
        self::assertSame('', (string) ($_SERVER['ANTHROPIC_API_KEY'] ?? ''), 'the test env has no keys');
        self::assertSame('', (string) ($_SERVER['DEEPGRAM_API_KEY'] ?? ''));
        $this->exportBothThenEmptyTheDatabase();
        $this->command('app:load-demo')->execute([]);

        $comparison = self::getContainer()->get(SpeechTools::class)->compareSpeeches(self::BASELINE_ID, self::IMPROVED_ID);

        self::assertSame(4.0, $comparison['baseline']['fillersPerMinute']);
        self::assertSame(1.33, $comparison['speech']['fillersPerMinute']);
        self::assertSame(-2.67, $comparison['fillersPerMinuteChange']);
        self::assertSame('um', $comparison['mostImproved']);
        self::assertNull($comparison['mostWorsened']);
    }

    public function testLoadWithoutDemoFilesSaysHowToMakeThem(): void
    {
        $tester = $this->command('app:load-demo');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('app:export-demo', $this->display($tester));
    }

    private function exportBothThenEmptyTheDatabase(): void
    {
        $this->createBaseline();
        $this->createImproved();
        $export = $this->command('app:export-demo');
        self::assertSame(Command::SUCCESS, $export->execute(['id' => self::BASELINE_ID, 'name' => 'week-1']));
        self::assertSame(Command::SUCCESS, $export->execute(['id' => self::IMPROVED_ID, 'name' => 'week-2']));

        $this->entityManager = self::recreateDatabase();
        $this->entityManager->clear();
        $this->filesystem->remove([$this->recordingsDir.'/'.self::BASELINE_ID.'.webm', $this->recordingsDir.'/'.self::IMPROVED_ID.'.mp4']);
    }

    /**
     * 60 s, 4 Fillers: "um" ×3 and a judged "like"; "you know" rejected.
     */
    private function createBaseline(): Speech
    {
        self::mockTime('2026-11-18 19:30:00');
        $speech = new Speech(Uuid::fromString(self::BASELINE_ID), self::BASELINE_ID.'.webm', 'audio/webm', 59.0, 'Week 1: my first speech');
        $speech->recordTranscript(FakeTranscriber::transcript(['Um,', 'so', 'I', 'um', 'started', 'like', 'this,', 'you', 'know,', 'um.'], 60.0));
        $speech->recordFillers([
            new FillerOccurrence('um', FillerKind::Sound, 0.0, 0.5, 0, 0, Verdict::Filler),
            new FillerOccurrence('so', FillerKind::Phrase, 1.0, 1.5, 1, 1, Verdict::NotFiller, 'starts the story'),
            new FillerOccurrence('um', FillerKind::Sound, 3.0, 3.5, 3, 3, Verdict::Filler),
            new FillerOccurrence('like', FillerKind::Phrase, 5.0, 5.5, 5, 5, Verdict::Filler, 'stands in for a pause'),
            new FillerOccurrence('you know', FillerKind::Phrase, 7.0, 8.5, 7, 8, Verdict::NotFiller, 'asks the listener'),
            new FillerOccurrence('um', FillerKind::Sound, 9.0, 9.5, 9, 9, Verdict::Filler),
        ]);
        $speech->recordCoaching(new Coaching('Plenty of "um": 3 in a minute.', ['"um" at 0:00, 0:03 and 0:09.'], ['Pause instead.']));
        $speech->markDone();

        return $this->save($speech, 'tone.webm');
    }

    /**
     * 45 s, 1 Filler: "um".
     */
    private function createImproved(): Speech
    {
        self::mockTime('2026-11-20 19:30:00');
        $speech = new Speech(Uuid::fromString(self::IMPROVED_ID), self::IMPROVED_ID.'.mp4', 'audio/mp4', 44.0, 'Week 2: better');
        $speech->recordTranscript(FakeTranscriber::transcript(['This', 'time', 'I', 'um', 'paused.'], 45.0));
        $speech->recordFillers([new FillerOccurrence('um', FillerKind::Sound, 3.0, 3.5, 3, 3, Verdict::Filler)]);
        $speech->recordCoaching(new Coaching('Much better.', [], ['Keep pausing.']));
        $speech->markDone();

        return $this->save($speech, 'tone.mp4');
    }

    private function save(Speech $speech, string $fixture): Speech
    {
        $this->filesystem->copy(\dirname(__DIR__).'/Fixtures/audio/'.$fixture, $this->recordingsDir.'/'.$speech->getRecordingFilename(), true);
        $this->entityManager->persist($speech);
        $this->entityManager->flush();

        return $speech;
    }

    private function find(string $id): Speech
    {
        $speech = self::getContainer()->get(SpeechRepository::class)->find(Uuid::fromString($id));
        self::assertInstanceOf(Speech::class, $speech);

        return $speech;
    }

    /**
     * The output with SymfonyStyle's line wrapping undone.
     */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find($name));
    }
}
