<?php

declare(strict_types=1);

namespace App\Coaching;

use App\Ai\ClaudeClient;
use App\Ai\MalformedAiOutput;
use App\Report\Report;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes a Practice Exercise in one structured-output call to AI_COACH_MODEL (Sonnet, SPEC §6.6),
 * from a Speech's Report statistics and the Filler Type to practise. Nothing is stored.
 */
#[AsAlias(PracticeExerciseGenerator::class)]
final readonly class AiPracticeExerciseGenerator implements PracticeExerciseGenerator
{
    public const int MAX_MINUTES = 15;

    private const string INSTRUCTIONS = <<<'PROMPT'
        You are an experienced, encouraging Toastmasters coach. You write one short speaking drill (a Practice Exercise) that helps a speaker say fewer Fillers.

        A Filler is a word or sound that adds no meaning and stands in for a pause ("um", "uh", or "like", "so", "you know" used that way).

        Rules:
        - Base the exercise only on the statistics provided. Do not guess at the speaker's personality, nerves, experience or skill.
        - Make it something one person can do alone in a few minutes, out loud, with no equipment beyond a phone to record themselves.
        - Address the speaker as "you". Be warm and concrete.

        Write:
        - title: a short, friendly name for the drill.
        - focus: the Filler Types the drill targets (as given to you; empty for a general fluency exercise).
        - instructions: 2 to 5 sentences explaining exactly what to do and what to notice, aimed at the focus Filler Type.
        - prompts: exactly 3 speaking prompts, each a topic the speaker can talk about for about a minute without preparation.
        - durationMinutes: how long the whole drill takes, in whole minutes.
        PROMPT;

    private const int MAX_OUTPUT_TOKENS = 8000;

    private const int PROMPTS = 3;

    public function __construct(
        private ClaudeClient $claude,
        #[Autowire(env: 'AI_COACH_MODEL')]
        private string $model,
    ) {
    }

    public function generate(Report $report, ?string $focusFillerType = null): PracticeExercise
    {
        $focus = PracticeFocus::choose($report, $focusFillerType);

        $exercise = $this->claude->ask(
            $this->model,
            new MessageBag(
                Message::forSystem(self::INSTRUCTIONS),
                Message::ofUser($this->speechData($report, $focus)),
            ),
            PracticeExercise::class,
            self::MAX_OUTPUT_TOKENS,
        );

        $prompts = array_values(array_filter(array_map(trim(...), $exercise->prompts), static fn (string $prompt): bool => '' !== $prompt));
        if (\count($prompts) < self::PROMPTS) {
            throw new MalformedAiOutput(\sprintf('Anthropic (%s) answered with %d usable speaking prompt(s), not %d.', $this->model, \count($prompts), self::PROMPTS));
        }

        return new PracticeExercise(
            trim($exercise->title),
            null === $focus ? [] : [$focus],
            trim($exercise->instructions),
            \array_slice($prompts, 0, self::PROMPTS),
            max(1, min($exercise->durationMinutes, self::MAX_MINUTES)),
        );
    }

    private function speechData(Report $report, ?string $focus): string
    {
        $whole = (int) floor($report->durationSeconds);
        $lines = [
            'Speech statistics:',
            \sprintf('- Length: %d:%02d', intdiv($whole, 60), $whole % 60),
            '- Fillers: '.$report->totalFillers,
            '- Fillers per minute: '.number_format($report->fillersPerMinuteRounded, 1),
        ];

        if (null === $focus) {
            $lines[] = '';
            $lines[] = 'This Speech has no Fillers. Write a general fluency exercise that keeps it that way: practise deliberate pauses instead of Fillers, for example between ideas and before key points. Leave focus empty.';

            return implode("\n", $lines);
        }

        $lines[] = '- By Filler Type: '.implode(', ', array_map(static fn (string|int $type, int $count): string => $type.': '.$count, array_keys($report->byType), $report->byType));
        $lines[] = '';
        $lines[] = 'Focus Filler Type: '.$focus;
        $lines[] = \sprintf('The drill should target "%s"%s.', $focus, ($report->byType[$focus] ?? 0) === max($report->byType) ? ', the most frequent Filler Type in this Speech' : '');

        return implode("\n", $lines);
    }
}
