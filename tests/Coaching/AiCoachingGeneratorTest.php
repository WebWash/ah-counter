<?php

declare(strict_types=1);

namespace App\Tests\Coaching;

use App\Ai\ClaudeClient;
use App\Ai\MalformedAiOutput;
use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Coaching\AiCoachingGenerator;
use App\Coaching\Coaching;
use App\Report\Report;
use App\Report\TranscriptMarker;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\Fake\RecordingPlatform;
use App\Transcription\Transcript;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * AiCoachingGenerator against a mock Symfony AI platform (SPEC §6.5, §10). No real API calls.
 */
final class AiCoachingGeneratorTest extends TestCase
{
    private RecordingPlatform $platform;

    public function testReturnsTheModelsCoaching(): void
    {
        $coaching = $this->generate(new ObjectResult(new Coaching(
            'Clear structure; "um" was your most frequent Filler.',
            ['"um" twice, at 0:02 and 1:11.'],
            ['Pause and breathe where you would say "um".'],
        )));

        self::assertSame('Clear structure; "um" was your most frequent Filler.', $coaching->summary);
        self::assertSame(['"um" twice, at 0:02 and 1:11.'], $coaching->patterns);
        self::assertSame(['Pause and breathe where you would say "um".'], $coaching->tips);
    }

    public function testThePromptGivesTheCountsAndEachCountedFillerWithItsTimeAndContext(): void
    {
        $this->generate(new ObjectResult(new Coaching('Fine.', [], ['Pause.'])));

        self::assertCount(1, $this->platform->calls);
        self::assertSame('claude-sonnet-test', $this->platform->calls[0]['model']);
        self::assertSame(Coaching::class, $this->platform->calls[0]['options']['response_format']);
        self::assertGreaterThanOrEqual(4000, $this->platform->calls[0]['options'][PlatformOptions::MAX_OUTPUT_TOKENS] ?? 0);

        $user = $this->platform->userPrompt();
        self::assertStringContainsString('Length: 1:30', $user);
        self::assertStringContainsString('Fillers: 3 (2 Filler Sounds, 1 Filler Phrase)', $user);
        self::assertStringContainsString('Fillers per minute: 2.0', $user);
        self::assertStringContainsString('um: 2', $user);
        self::assertStringContainsString('like: 1', $user);
        self::assertStringContainsString('Most frequent Filler Type: um', $user);
        // Each counted Filler: its time and about 6 words of context, the Filler in brackets.
        self::assertStringContainsString('0:02 um: So I [um] think it was', $user);
        self::assertStringContainsString('1:11 um: … and you know [um] done.', $user);
        self::assertStringContainsString('0:06 like: … think it was [like] huge and you …', $user);
        self::assertStringNotContainsString('you know:', $user, 'a rejected Candidate is not a Filler');
        self::assertStringContainsString('So I um think it was like huge and you know um done.', $user, 'the full Transcript');

        $system = $this->platform->systemPrompt();
        self::assertStringContainsString('only on the data', $system);
        self::assertStringContainsString('personality', $system);
        self::assertStringContainsString('Toastmasters', $system);
    }

    public function testASpeechWithoutFillersIsPraisedWithExactlyOneTip(): void
    {
        $report = new Report(30.0, 0, 0.0, 0.0, [], 0, 0, null, null);

        $coaching = $this->generate(
            new ObjectResult(new Coaching('No Fillers at all: excellent.', [], ['Keep pausing between ideas.', 'Vary your pace.'])),
            $report,
            [],
        );

        self::assertStringContainsString('no Fillers', $this->platform->userPrompt());
        self::assertStringContainsString('exactly one tip', $this->platform->userPrompt());
        self::assertSame(['Keep pausing between ideas.'], $coaching->tips, 'praise plus one tip, even if the model offers more');
    }

    public function testNoMoreThanThreePatternsOrTipsAreKept(): void
    {
        $coaching = $this->generate(new ObjectResult(new Coaching('Fine.', ['1', '2', '3', '4'], ['a', 'b', 'c', 'd'])));

        self::assertSame(['1', '2', '3'], $coaching->patterns);
        self::assertSame(['a', 'b', 'c'], $coaching->tips);
    }

    public function testOutputWithTheWrongShapeIsAShortLivedFailure(): void
    {
        $this->expectException(MalformedAiOutput::class);

        $this->generate(new ObjectResult(['summary' => 'Fine.']));
    }

    public function testJsonFromTheModelIsDecodedIntoCoaching(): void
    {
        $coaching = $this->generate(new TextResult('{"summary":"Good.","patterns":["um at 0:02"],"tips":["Pause."]}'), structuredOutput: true);

        self::assertEquals(new Coaching('Good.', ['um at 0:02'], ['Pause.']), $coaching);
    }

    /**
     * @param list<FillerOccurrence>|null $fillers defaults to two "um" (the second given a 1:11 time), one
     *                                    "like", an unjudged "so" and a rejected "you know"
     */
    private function generate(ResultInterface $answer, ?Report $report = null, ?array $fillers = null, bool $structuredOutput = false): Coaching
    {
        $this->platform = new RecordingPlatform($answer, $structuredOutput);

        return (new AiCoachingGenerator(new ClaudeClient($this->platform), new TranscriptMarker(), 'claude-sonnet-test'))
            ->generate($report ?? $this->report(), $this->transcript(), $fillers ?? $this->fillers());
    }

    private function transcript(): Transcript
    {
        return FakeTranscriber::transcript(['So', 'I', 'um', 'think', 'it', 'was', 'like', 'huge', 'and', 'you', 'know', 'um', 'done.'], 90.0);
    }

    /**
     * @return list<FillerOccurrence>
     */
    private function fillers(): array
    {
        return [
            new FillerOccurrence('so', FillerKind::Phrase, 0.0, 0.5, 0, 0, Verdict::Unjudged),
            new FillerOccurrence('um', FillerKind::Sound, 2.0, 2.5, 2, 2, Verdict::Filler),
            new FillerOccurrence('like', FillerKind::Phrase, 6.0, 6.5, 6, 6, Verdict::Filler, 'Hedge.'),
            new FillerOccurrence('you know', FillerKind::Phrase, 9.0, 10.5, 9, 10, Verdict::NotFiller, 'Asks the listener.'),
            new FillerOccurrence('um', FillerKind::Sound, 71.0, 71.5, 11, 11, Verdict::Filler),
        ];
    }

    private function report(): Report
    {
        return new Report(90.0, 3, 2.0, 2.0, ['um' => 2, 'like' => 1], 2, 1, 'um', null);
    }
}
