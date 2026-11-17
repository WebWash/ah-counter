<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Coaching\Coaching;
use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Mcp\SpeechTools;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\RecreatesDatabase;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

/**
 * The MCP read tools, called as the MCP server calls them (SPEC §8, ticket 16).
 */
final class SpeechToolsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RecreatesDatabase;

    private EntityManagerInterface $entityManager;
    private SpeechTools $tools;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::recreateDatabase();
        $this->tools = self::getContainer()->get(SpeechTools::class);
    }

    public function testListSpeechesGivesTheNewestFirstWithStatisticsOnlyForDoneSpeeches(): void
    {
        self::mockTime('2026-11-20 09:00:00');
        $done = $this->createDoneSpeech('Club speech');
        self::mockTime('2026-11-20 10:00:00');
        $processing = $this->persist(new Speech(Uuid::v4(), 'b.webm', 'audio/webm', 42.0));
        $processing->startStep(SpeechStatus::Transcribing);
        $this->entityManager->flush();

        $result = $this->tools->listSpeeches();

        self::assertSame([
            [
                'id' => $processing->getId()->toRfc4122(),
                'title' => '20 Nov 2026, 10:00',
                'createdAt' => '2026-11-20T10:00:00+00:00',
                'status' => 'transcribing',
                'durationSeconds' => 42.0,
                'fillerCount' => null,
                'fillersPerMinute' => null,
            ],
            [
                'id' => $done->getId()->toRfc4122(),
                'title' => 'Club speech',
                'createdAt' => '2026-11-20T09:00:00+00:00',
                'status' => 'done',
                'durationSeconds' => 30.0,
                'fillerCount' => 3,
                'fillersPerMinute' => 6.0,
            ],
        ], $result['speeches']);
    }

    public function testListSpeechesHonoursTheLimitAndCapsItAtFifty(): void
    {
        for ($i = 0; $i < 52; ++$i) {
            $this->persist(new Speech(Uuid::v4(), $i.'.webm', 'audio/webm', 30.0));
        }
        $this->entityManager->flush();

        self::assertCount(10, $this->tools->listSpeeches()['speeches'], 'the default');
        self::assertCount(3, $this->tools->listSpeeches(3)['speeches']);
        self::assertCount(50, $this->tools->listSpeeches(500)['speeches']);
    }

    public function testGetSpeechReportGivesTheStatisticsCoachingAndBracketedTranscript(): void
    {
        self::mockTime('2026-11-20 09:00:00');
        $speech = $this->createDoneSpeech('Club speech');

        $report = $this->tools->getSpeechReport($speech->getId()->toRfc4122());

        self::assertSame($speech->getId()->toRfc4122(), $report['id']);
        self::assertSame('Club speech', $report['title']);
        self::assertSame('2026-11-20T09:00:00+00:00', $report['createdAt']);
        self::assertSame(30.0, $report['durationSeconds']);
        self::assertSame(3, $report['totalFillers']);
        self::assertSame(6.0, $report['fillersPerMinute']);
        self::assertArrayNotHasKey('fillersPerMinuteRounded', $report, 'one rounded value is enough for a client');
        self::assertSame(['um' => 2, 'like' => 1], $report['byType']);
        self::assertSame(2, $report['soundCount']);
        self::assertSame(1, $report['phraseCount']);
        self::assertSame('um', $report['topType']);
        self::assertSame([
            'summary' => 'Good pace; "um" was your most frequent Filler.',
            'patterns' => ['"um" twice, at 0:02 and 0:11.'],
            'tips' => ['Pause instead of saying um.'],
        ], $report['coaching']);
        self::assertSame('So I [um], think it was [like] huge and you know [um] done.', $report['transcript']);
    }

    public function testAMalformedIdIsAToolError(): void
    {
        $this->expectToolError('"not-a-uuid" is not a Speech ID');

        $this->tools->getSpeechReport('not-a-uuid');
    }

    public function testAnUnknownSpeechIsAToolError(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $this->expectToolError('There is no Speech with ID '.$id);

        $this->tools->getSpeechReport($id);
    }

    public function testASpeechThatIsNotDoneIsAToolErrorNamingItsStatus(): void
    {
        $speech = $this->persist(new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0));
        $speech->startStep(SpeechStatus::Analysing);
        $this->entityManager->flush();

        $this->expectToolError(\sprintf('Speech %s is still analysing', $speech->getId()));

        $this->tools->getSpeechReport($speech->getId()->toRfc4122());
    }

    public function testAFailedSpeechIsAToolErrorGivingItsError(): void
    {
        $speech = $this->persist(new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0));
        $speech->markFailed('We couldn\'t hear any speech in this recording.');
        $this->entityManager->flush();

        $this->expectToolError(\sprintf('Speech %s failed: We couldn\'t hear any speech in this recording.', $speech->getId()));

        $this->tools->getSpeechReport($speech->getId()->toRfc4122());
    }

    public function testSeesASpeechTheWorkerFinishedInAnotherProcess(): void
    {
        $speech = $this->persist(new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0));
        $speech->startStep(SpeechStatus::Coaching);
        $this->recordAnalysis($speech);
        $this->entityManager->flush();
        $id = $speech->getId()->toRfc4122();

        try {
            $this->tools->getSpeechReport($id);
            self::fail('a Speech that is still coaching has no Report');
        } catch (ToolCallException) {
        }

        // The worker finishes it through its own database connection.
        $worker = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        self::assertSame(1, $worker->executeStatement('UPDATE speech SET status = ? WHERE id = ?', ['done', $speech->getId()->toBinary()]));
        $worker->close();

        self::assertSame(3, $this->tools->getSpeechReport($id)['totalFillers']);
    }

    public function testListFillersGivesEachCountedFillerInTimeOrderWithItsContext(): void
    {
        $speech = $this->createDoneSpeech();

        $result = $this->tools->listFillers($speech->getId()->toRfc4122());

        self::assertSame($speech->getId()->toRfc4122(), $result['speechId']);
        self::assertSame([
            ['type' => 'um', 'kind' => 'sound', 'start' => 2.0, 'end' => 2.5, 'context' => 'So I [um], think it was …', 'verdict' => 'filler', 'reason' => null],
            ['type' => 'like', 'kind' => 'phrase', 'start' => 6.0, 'end' => 6.5, 'context' => '… think it was [like] huge and you …', 'verdict' => 'filler', 'reason' => 'stands in for a pause'],
            ['type' => 'um', 'kind' => 'sound', 'start' => 11.0, 'end' => 11.5, 'context' => '… and you know [um] done.', 'verdict' => 'filler', 'reason' => null],
        ], $result['fillers']);
    }

    public function testListFillersCanIncludeTheCandidatesThatDoNotCount(): void
    {
        $speech = $this->createDoneSpeech();

        $fillers = $this->tools->listFillers($speech->getId()->toRfc4122(), includeRejected: true)['fillers'];

        self::assertSame(['so', 'um', 'like', 'you know', 'um'], array_column($fillers, 'type'));
        self::assertSame(['unjudged', 'filler', 'filler', 'not_filler', 'filler'], array_column($fillers, 'verdict'));
        self::assertSame('asks the listener', $fillers[3]['reason']);
        self::assertSame('… like huge and [you know] um done.', $fillers[3]['context']);
    }

    public function testListFillersNeedsADoneSpeech(): void
    {
        $speech = $this->persist(new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0));
        $this->entityManager->flush();

        $this->expectToolError('is waiting to be processed');

        $this->tools->listFillers($speech->getId()->toRfc4122());
    }

    private function expectToolError(string $message): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
    }

    /**
     * "So I um think it was like huge and you know um done.", 30 s long: um ×2 and a judged
     * "like" count; "you know" was rejected and "So" left unjudged.
     */
    private function createDoneSpeech(?string $title = null): Speech
    {
        $speech = new Speech(Uuid::v4(), 'speech.webm', 'audio/webm', 29.5, $title);
        $this->recordAnalysis($speech);
        $speech->recordCoaching(new Coaching('Good pace; "um" was your most frequent Filler.', ['"um" twice, at 0:02 and 0:11.'], ['Pause instead of saying um.']));
        $speech->markDone();
        $this->persist($speech);
        $this->entityManager->flush();

        return $speech;
    }

    private function recordAnalysis(Speech $speech): void
    {
        $speech->recordTranscript(FakeTranscriber::transcript(['So', 'I', 'um,', 'think', 'it', 'was', 'like', 'huge', 'and', 'you', 'know', 'um', 'done.'], 30.0));
        $speech->recordFillers([
            new FillerOccurrence('so', FillerKind::Phrase, 0.0, 0.5, 0, 0, Verdict::Unjudged),
            new FillerOccurrence('um', FillerKind::Sound, 2.0, 2.5, 2, 2, Verdict::Filler),
            new FillerOccurrence('like', FillerKind::Phrase, 6.0, 6.5, 6, 6, Verdict::Filler, 'stands in for a pause'),
            new FillerOccurrence('you know', FillerKind::Phrase, 9.0, 10.5, 9, 10, Verdict::NotFiller, 'asks the listener'),
            new FillerOccurrence('um', FillerKind::Sound, 11.0, 11.5, 11, 11, Verdict::Filler),
        ]);
    }

    private function persist(Speech $speech): Speech
    {
        $this->entityManager->persist($speech);

        return $speech;
    }
}
