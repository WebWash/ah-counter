<?php

declare(strict_types=1);

namespace App\Tests\Analysis;

use App\Ai\ClaudeClient;
use App\Ai\MalformedAiOutput;
use App\Analysis\AiCandidateJudge;
use App\Analysis\CandidateVerdict;
use App\Analysis\CandidateVerdicts;
use App\Analysis\FillerDetector;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Processing\PermanentFailure;
use App\Tests\Fake\CollectingLogger;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\Fake\RecordingPlatform;
use App\Transcription\Transcript;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * AiCandidateJudge against a mock Symfony AI platform (SPEC §6.3, §10). No real API calls.
 */
final class AiCandidateJudgeTest extends TestCase
{
    /** "So, um, I was, like, thinking that, you know, it's like a dream." */
    private Transcript $transcript;

    /** @var list<FillerOccurrence> so (c1), um, like (c2), you know (c3), like (c4) */
    private array $occurrences;

    private RecordingPlatform $platform;

    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->transcript = FakeTranscriber::transcript(
            ['So,', 'um,', 'I', 'was,', 'like,', 'thinking', 'that,', 'you', 'know,', 'it\'s', 'like', 'a', 'dream.'],
            20.0,
        );
        $this->occurrences = (new FillerDetector())->detect($this->transcript);
        $this->logger = new CollectingLogger();
    }

    public function testAppliesEachVerdictAndReasonToItsCandidate(): void
    {
        $judged = $this->judge(new ObjectResult(new CandidateVerdicts([
            new CandidateVerdict('c1', true, 'Opens the sentence with no meaning.'),
            new CandidateVerdict('c2', true, 'Hedge before "thinking".'),
            new CandidateVerdict('c3', true, 'Stands in for a pause.'),
            new CandidateVerdict('c4', false, 'Comparison: "like a dream".'),
        ])));

        self::assertSame(
            [
                ['so', 'filler', 'Opens the sentence with no meaning.'],
                ['um', 'filler', null],
                ['like', 'filler', 'Hedge before "thinking".'],
                ['you know', 'filler', 'Stands in for a pause.'],
                ['like', 'not_filler', 'Comparison: "like a dream".'],
            ],
            array_map(static fn (FillerOccurrence $o): array => [$o->type, $o->verdict->value, $o->reason], $judged),
        );
        self::assertSame($this->occurrences[3]->start, $judged[3]->start, 'timestamps and word indices are kept');
        self::assertSame([], $this->logger->warnings());
    }

    public function testThePromptMarksEveryCandidateInlineExactlyOnce(): void
    {
        $this->judge(new ObjectResult(new CandidateVerdicts([])));

        self::assertCount(1, $this->platform->calls);
        self::assertSame('claude-haiku-test', $this->platform->calls[0]['model']);
        self::assertSame(CandidateVerdicts::class, $this->platform->calls[0]['options']['response_format']);

        $user = $this->platform->userPrompt();
        self::assertStringContainsString('[[c1:So,]] um, I was, [[c2:like,]] thinking that, [[c3:you know,]] it\'s [[c4:like]] a dream.', $user);
        foreach (['c1', 'c2', 'c3', 'c4'] as $id) {
            self::assertSame(1, substr_count($user, '[['.$id.':'), $id.' is marked exactly once');
        }
        self::assertStringNotContainsString('[[c5', $user);
        self::assertStringNotContainsString(':um', $user, 'Filler Sounds are never sent for judging');

        $system = $this->platform->systemPrompt();
        self::assertStringContainsString('only the marked', $system);
        self::assertStringContainsString('prefer not filler', strtolower($system));
    }

    public function testACandidateTheJudgeLeftOutStaysUnjudgedAndIsLogged(): void
    {
        $judged = $this->judge(new ObjectResult(new CandidateVerdicts([
            new CandidateVerdict('c1', true, 'Opener.'),
            new CandidateVerdict('c3', false, 'Literal.'),
        ])));

        self::assertSame(['filler', 'filler', 'unjudged', 'not_filler', 'unjudged'], array_map(static fn (FillerOccurrence $o): string => $o->verdict->value, $judged));
        self::assertCount(2, $this->logger->warnings());
        self::assertStringContainsString('c2', $this->logger->warnings()[0]);
        self::assertStringContainsString('c4', $this->logger->warnings()[1]);
    }

    public function testUnknownIdsAreIgnoredAndTheFirstOfTwoDuplicateVerdictsWins(): void
    {
        $judged = $this->judge(new ObjectResult(new CandidateVerdicts([
            new CandidateVerdict('c9', true, 'Not in this Speech.'),
            new CandidateVerdict('c1', false, 'First answer.'),
            new CandidateVerdict('c1', true, 'Second answer.'),
            new CandidateVerdict('c2', true, 'Hedge.'),
            new CandidateVerdict('c3', true, 'Pause.'),
            new CandidateVerdict('c4', false, 'Comparison.'),
        ])));

        self::assertCount(5, $judged, 'an unknown ID adds nothing');
        self::assertSame(Verdict::NotFiller, $judged[0]->verdict);
        self::assertSame('First answer.', $judged[0]->reason);
    }

    public function testOutputWithTheWrongShapeIsAShortLivedFailure(): void
    {
        $this->expectException(MalformedAiOutput::class);

        $this->judge(new ObjectResult(['verdicts' => 'not a list']));
    }

    public function testRealJsonFromTheModelIsDecodedIntoVerdicts(): void
    {
        $judged = $this->judge(new TextResult('{"verdicts":[{"id":"c2","isFiller":true,"reason":"Hedge."}]}'), structuredOutput: true);

        self::assertSame(Verdict::Filler, $judged[2]->verdict);
        self::assertSame('Hedge.', $judged[2]->reason);
    }

    public function testJsonCutOffMidAnswerIsAShortLivedFailure(): void
    {
        $this->expectException(MalformedAiOutput::class);

        // What the model sends when it runs out of output tokens part-way through.
        $this->judge(new TextResult('{"verdicts":[{"id":"c1","isFiller":true,"reason":"Op'), structuredOutput: true);
    }

    public function testTheCallHasRoomForEveryVerdict(): void
    {
        $this->judge(new ObjectResult(new CandidateVerdicts([])));

        // The bridge's default of 1000 output tokens truncated a 10-Candidate answer once thinking
        // was counted (2026-11-17, clip-1).
        self::assertGreaterThanOrEqual(4000, $this->platform->calls[0]['options'][PlatformOptions::MAX_OUTPUT_TOKENS] ?? 0);
    }

    public function testATextAnswerInsteadOfStructuredOutputIsAShortLivedFailure(): void
    {
        $this->expectException(MalformedAiOutput::class);

        $this->judge(new TextResult('c1: filler'));
    }

    public function testARejectedApiKeyIsPermanent(): void
    {
        try {
            $this->judge(static fn (): never => throw new AuthenticationException('invalid x-api-key'));
            self::fail('Expected a permanent failure.');
        } catch (PermanentFailure $e) {
            self::assertStringContainsString('ANTHROPIC_API_KEY', $e->userMessage);
            self::assertStringNotContainsString('x-api-key', $e->userMessage, 'the raw provider message stays in the log');
        }
    }

    public function testWithoutCandidatesNoCallIsMade(): void
    {
        $sounds = array_values(array_filter($this->occurrences, static fn (FillerOccurrence $o): bool => Verdict::Filler === $o->verdict));

        self::assertSame($sounds, $this->judge(new ObjectResult(new CandidateVerdicts([])), $sounds));
        self::assertSame([], $this->platform->calls);
    }

    /**
     * @param ResultInterface|\Closure(): ResultInterface $answer
     * @param list<FillerOccurrence>|null                 $occurrences defaults to the detected ones
     *
     * @return list<FillerOccurrence>
     */
    private function judge(ResultInterface|\Closure $answer, ?array $occurrences = null, bool $structuredOutput = false): array
    {
        $this->platform = new RecordingPlatform($answer, $structuredOutput);

        return (new AiCandidateJudge(new ClaudeClient($this->platform), 'claude-haiku-test', $this->logger))
            ->judge($this->transcript, $occurrences ?? $this->occurrences);
    }
}
