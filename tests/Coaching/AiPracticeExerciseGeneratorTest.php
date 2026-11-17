<?php

declare(strict_types=1);

namespace App\Tests\Coaching;

use App\Ai\ClaudeClient;
use App\Ai\MalformedAiOutput;
use App\Coaching\AiPracticeExerciseGenerator;
use App\Coaching\FillerTypeNotInSpeech;
use App\Coaching\PracticeExercise;
use App\Report\Report;
use App\Tests\Fake\RecordingPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * AiPracticeExerciseGenerator against a mock Symfony AI platform (SPEC §6.6, §10). No real API calls.
 */
final class AiPracticeExerciseGeneratorTest extends TestCase
{
    private RecordingPlatform $platform;

    public function testTheFocusDefaultsToTheMostFrequentFillerType(): void
    {
        $exercise = $this->generate($this->answer());

        self::assertSame(['um'], $exercise->focus, 'set from the Report, whatever the model says');
        self::assertSame('Pause, don\'t "um"', $exercise->title);
        self::assertSame(['Describe your morning.', 'Explain your job.', 'Argue for a four-day week.'], $exercise->prompts);
        self::assertSame(5, $exercise->durationMinutes);

        $user = $this->platform->userPrompt();
        self::assertStringContainsString('Focus Filler Type: um', $user);
        self::assertStringContainsString('Length: 1:30', $user);
        self::assertStringContainsString('Fillers: 5', $user);
        self::assertStringContainsString('Fillers per minute: 3.3', $user);
        self::assertStringContainsString('um: 3, like: 2', $user);
    }

    public function testAskedForAFocusFillerTypeItPractisesThatOne(): void
    {
        $exercise = $this->generate($this->answer(), focus: ' Like ');

        self::assertSame(['like'], $exercise->focus);
        self::assertStringContainsString('Focus Filler Type: like', $this->platform->userPrompt());
    }

    public function testAFocusTheSpeechDoesNotShowIsRejectedWithTheTypesItDoes(): void
    {
        try {
            $this->generate($this->answer(), focus: 'you know');
            self::fail('"you know" is not in this Speech');
        } catch (FillerTypeNotInSpeech $e) {
            self::assertSame(['um', 'like'], $e->typesInSpeech);
            self::assertStringContainsString('"you know"', $e->getMessage());
            self::assertStringContainsString('um, like', $e->getMessage());
        }
        self::assertSame([], $this->platform->calls, 'no AI call');
    }

    public function testASpeechWithoutFillersGetsAGeneralFluencyExercise(): void
    {
        $exercise = $this->generate($this->answer(), new Report(45.0, 0, 0.0, 0.0, [], 0, 0, null, null));

        self::assertSame([], $exercise->focus);
        $user = $this->platform->userPrompt();
        self::assertStringContainsString('no Fillers', $user);
        self::assertStringContainsString('general fluency exercise', $user);
        self::assertStringContainsString('deliberate pauses', $user);
        self::assertStringNotContainsString('Focus Filler Type', $user);
    }

    public function testTheRequestUsesTheCoachModelWithStructuredOutputAndRoomToThink(): void
    {
        $this->generate($this->answer());

        self::assertCount(1, $this->platform->calls);
        self::assertSame('claude-sonnet-test', $this->platform->calls[0]['model']);
        self::assertSame(PracticeExercise::class, $this->platform->calls[0]['options']['response_format']);
        self::assertGreaterThanOrEqual(4000, $this->platform->calls[0]['options'][PlatformOptions::MAX_OUTPUT_TOKENS] ?? 0);
        self::assertStringContainsString('Toastmasters', $this->platform->systemPrompt());
        self::assertStringContainsString('3 speaking prompts', $this->platform->systemPrompt());
    }

    public function testKeepsThreePromptsAndASensibleDuration(): void
    {
        $exercise = $this->generate(new ObjectResult(new PracticeExercise('Drill', ['um'], 'Speak.', ['a', 'b', 'c', 'd'], 45)));

        self::assertSame(['a', 'b', 'c'], $exercise->prompts);
        self::assertSame(AiPracticeExerciseGenerator::MAX_MINUTES, $exercise->durationMinutes);

        $short = $this->generate(new ObjectResult(new PracticeExercise('Drill', ['um'], 'Speak.', ['a'], 0)));
        self::assertSame(1, $short->durationMinutes);
    }

    public function testJsonFromTheModelIsDecodedIntoAPracticeExercise(): void
    {
        $exercise = $this->generate(new TextResult('{"title":"Drill","focus":["um"],"instructions":"Speak slowly.","prompts":["One","Two","Three"],"durationMinutes":4}'), structuredOutput: true);

        self::assertEquals(new PracticeExercise('Drill', ['um'], 'Speak slowly.', ['One', 'Two', 'Three'], 4), $exercise);
    }

    public function testOutputWithTheWrongShapeIsMalformed(): void
    {
        $this->expectException(MalformedAiOutput::class);

        $this->generate(new ObjectResult(['title' => 'Drill']));
    }

    private function answer(): ResultInterface
    {
        return new ObjectResult(new PracticeExercise(
            'Pause, don\'t "um"',
            ['something else'],
            'Speak for a minute on each prompt. Each time you feel an "um" coming, close your mouth and pause.',
            ['Describe your morning.', 'Explain your job.', 'Argue for a four-day week.'],
            5,
        ));
    }

    private function generate(ResultInterface $answer, ?Report $report = null, ?string $focus = null, bool $structuredOutput = false): PracticeExercise
    {
        $this->platform = new RecordingPlatform($answer, $structuredOutput);

        return (new AiPracticeExerciseGenerator(new ClaudeClient($this->platform), 'claude-sonnet-test'))
            ->generate($report ?? new Report(90.0, 5, 5 / 1.5, 3.3, ['um' => 3, 'like' => 2], 3, 2, 'um', null), $focus);
    }
}
