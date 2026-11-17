<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Ai\MalformedAiOutput;
use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Coaching\Coaching;
use App\Entity\Speech;
use App\Mcp\McpSpeeches;
use App\Mcp\PracticeExerciseTool;
use App\Processing\PermanentFailure;
use App\Report\ReportBuilder;
use App\Tests\Fake\CollectingLogger;
use App\Tests\Fake\FakePracticeExerciseGenerator;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The generate_practice_exercise MCP tool (SPEC §8, ticket 17), with a fake generator.
 */
final class PracticeExerciseToolTest extends KernelTestCase
{
    use RecreatesDatabase;

    private EntityManagerInterface $entityManager;
    private FakePracticeExerciseGenerator $generator;
    private CollectingLogger $logger;
    private PracticeExerciseTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::recreateDatabase();
        $this->generator = new FakePracticeExerciseGenerator();
        $this->logger = new CollectingLogger();
        $this->tool = new PracticeExerciseTool(
            self::getContainer()->get(McpSpeeches::class),
            self::getContainer()->get(ReportBuilder::class),
            $this->generator,
            $this->logger,
        );
    }

    public function testGeneratesAnExerciseForTheSpeechsReport(): void
    {
        $id = $this->createDoneSpeech()->getId()->toRfc4122();

        $exercise = $this->tool->generatePracticeExercise($id);

        self::assertSame([
            'speechId' => $id,
            'title' => 'Pause drill',
            'focus' => ['um'],
            'instructions' => 'Pause instead.',
            'prompts' => ['One', 'Two', 'Three'],
            'durationMinutes' => 5,
        ], $exercise);
        self::assertSame(['um' => 2, 'like' => 1], $this->generator->lastReport?->byType);
        self::assertNull($this->generator->lastFocus, 'the generator picks the default focus');
    }

    public function testPassesTheFocusFillerTypeOn(): void
    {
        $id = $this->createDoneSpeech()->getId()->toRfc4122();

        self::assertSame(['like'], $this->tool->generatePracticeExercise($id, 'like')['focus']);
        self::assertSame('like', $this->generator->lastFocus);
    }

    public function testAFocusTheSpeechDoesNotShowIsAToolErrorListingTheTypesItDoes(): void
    {
        $id = $this->createDoneSpeech()->getId()->toRfc4122();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This Speech has no "so" Fillers. Its Filler Types are: um, like.');

        $this->tool->generatePracticeExercise($id, 'so');
    }

    public function testASpeechThatIsNotDoneIsAToolError(): void
    {
        $speech = new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0);
        $this->entityManager->persist($speech);
        $this->entityManager->flush();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is waiting to be processed');

        $this->tool->generatePracticeExercise($speech->getId()->toRfc4122());
    }

    public function testARefusedAiRequestIsAToolErrorPointingAtTheApiKey(): void
    {
        $id = $this->createDoneSpeech()->getId()->toRfc4122();
        $this->generator->failWith(new PermanentFailure('The AI service rejected our API key.', 'Anthropic said 401: invalid x-api-key'));

        try {
            $this->tool->generatePracticeExercise($id);
            self::fail('expected a tool error');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('ANTHROPIC_API_KEY', $e->getMessage());
            self::assertStringNotContainsString('x-api-key', $e->getMessage(), 'the raw provider message is only logged');
        }
        self::assertStringContainsString('Anthropic said 401: invalid x-api-key', implode("\n", $this->logger->records['error'] ?? []));
    }

    public function testAShortLivedAiFailureIsAToolErrorSayingToTryAgain(): void
    {
        $id = $this->createDoneSpeech()->getId()->toRfc4122();
        $this->generator->failWith(new MalformedAiOutput('cut off at max_tokens'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Try again in a moment');

        $this->tool->generatePracticeExercise($id);
    }

    private function createDoneSpeech(): Speech
    {
        $speech = new Speech(Uuid::v4(), 'speech.webm', 'audio/webm', 30.0);
        $speech->recordTranscript(FakeTranscriber::transcript(['So', 'I', 'um', 'think', 'it', 'was', 'like', 'huge,', 'um.'], 30.0));
        $speech->recordFillers([
            new FillerOccurrence('um', FillerKind::Sound, 2.0, 2.5, 2, 2, Verdict::Filler),
            new FillerOccurrence('like', FillerKind::Phrase, 6.0, 6.5, 6, 6, Verdict::Filler),
            new FillerOccurrence('um', FillerKind::Sound, 8.0, 8.5, 8, 8, Verdict::Filler),
        ]);
        $speech->recordCoaching(new Coaching('Fine.', [], ['Pause.']));
        $speech->markDone();
        $this->entityManager->persist($speech);
        $this->entityManager->flush();

        return $speech;
    }
}
