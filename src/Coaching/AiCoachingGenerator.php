<?php

declare(strict_types=1);

namespace App\Coaching;

use App\Ai\ClaudeClient;
use App\Analysis\FillerOccurrence;
use App\Report\Report;
use App\Report\TranscriptMarker;
use App\Transcription\Transcript;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the Coaching for a Speech in one structured-output call to AI_COACH_MODEL (Sonnet,
 * SPEC §6.5), from the Report statistics, each counted Filler in context, and the Transcript.
 */
#[AsAlias(CoachingGenerator::class)]
final readonly class AiCoachingGenerator implements CoachingGenerator
{
    private const string INSTRUCTIONS = <<<'PROMPT'
        You are an experienced, encouraging Toastmasters evaluator. You write the Coaching section of a filler-word report for one short English speech.

        Rules:
        - Base every claim only on the data provided: the statistics, the listed Fillers and the transcript. Never invent a count, a time or a quote.
        - Do not guess at the speaker's personality, nerves, confidence, experience or skill. Talk about the speech, not the person.
        - Be warm, specific and constructive, in the supportive tone of a Toastmasters evaluation. Address the speaker as "you".
        - A Filler is a word or sound that adds no meaning and stands in for a pause ("um", "uh", or "like", "so", "you know" used that way). Only the listed Fillers count; never call another word a Filler.

        Write:
        - summary: at most 60 words. Name the most frequent Filler Type and the overall rate, and mention one thing the speech did well.
        - patterns: up to 3 short observations about where or how the Fillers appear (for example at the start of sentences, clustered in one part of the speech, or around a particular topic). Each one must cite a time (m:ss, as listed) or a count from the data.
        - tips: up to 3 concrete techniques the speaker can practise next time, each aimed at the Fillers this speech actually shows.
        PROMPT;

    private const int MAX_OUTPUT_TOKENS = 8000;

    /** Words either side of each Filler: about 6 words of context in all. */
    private const int CONTEXT_WORDS = 3;

    private const int MAX_ITEMS = 3;

    public function __construct(
        private ClaudeClient $claude,
        private TranscriptMarker $marker,
        #[Autowire(env: 'AI_COACH_MODEL')]
        private string $model,
    ) {
    }

    public function generate(Report $report, Transcript $transcript, array $fillers): Coaching
    {
        $counted = array_values(array_filter($fillers, static fn (FillerOccurrence $filler): bool => $filler->isFiller()));

        $coaching = $this->claude->ask(
            $this->model,
            new MessageBag(
                Message::forSystem(self::INSTRUCTIONS),
                Message::ofUser($this->speechData($report, $transcript, $counted)),
            ),
            Coaching::class,
            self::MAX_OUTPUT_TOKENS,
        );

        // A Speech without Fillers gets praise plus one tip (SPEC §5 "Empty cases").
        $maxTips = 0 === $report->totalFillers ? 1 : self::MAX_ITEMS;

        return new Coaching(
            trim($coaching->summary),
            \array_slice($coaching->patterns, 0, self::MAX_ITEMS),
            \array_slice($coaching->tips, 0, $maxTips),
        );
    }

    /**
     * @param list<FillerOccurrence> $counted
     */
    private function speechData(Report $report, Transcript $transcript, array $counted): string
    {
        $lines = [
            'Speech statistics:',
            '- Length: '.$this->clock($report->durationSeconds),
            \sprintf('- Fillers: %d (%s, %s)', $report->totalFillers, $this->plural($report->soundCount, 'Filler Sound'), $this->plural($report->phraseCount, 'Filler Phrase')),
            '- Fillers per minute: '.number_format($report->fillersPerMinuteRounded, 1),
        ];

        if ([] === $counted) {
            $lines[] = '';
            $lines[] = 'This Speech has no Fillers. Praise that, leave patterns empty, and give exactly one tip that helps the speaker keep it up.';
        } else {
            $lines[] = '- By Filler Type: '.implode(', ', array_map(static fn (string $type, int $count): string => $type.': '.$count, array_keys($report->byType), $report->byType));
            $lines[] = '- Most frequent Filler Type: '.$report->topType;
            $lines[] = '';
            $lines[] = 'Every Filler, as "time type: context", with the Filler in [brackets]:';
            foreach ($counted as $filler) {
                $context = $this->marker->context($transcript, $filler, self::CONTEXT_WORDS);
                $lines[] = \sprintf('- %s %s: %s', $this->clock($filler->start), $filler->type, trim(\sprintf('%s [%s] %s', $context['before'], $context['filler'], $context['after'])));
            }
        }

        $lines[] = '';
        $lines[] = 'Transcript:';
        $lines[] = $transcript->text();

        return implode("\n", $lines);
    }

    private function clock(float $seconds): string
    {
        $whole = (int) floor($seconds);

        return \sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.(1 === $count ? '' : 's');
    }
}
