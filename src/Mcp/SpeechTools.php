<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Speech;
use App\Report\ReportBuilder;
use App\Report\SpeechComparer;
use App\Report\TranscriptMarker;
use App\Transcription\Transcript;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * The MCP read tools (SPEC §8): thin wrappers over SpeechRepository, ReportBuilder and SpeechComparer.
 *
 * Each returns a JSON object, so clients also get it as structuredContent (a bare list is only
 * sent as text before MCP protocol 2026-07-28).
 */
final readonly class SpeechTools
{
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 50;

    /** Words either side of a Filler in list_fillers: about 6 words of context in all. */
    private const int CONTEXT_WORDS = 3;

    public function __construct(
        private McpSpeeches $speeches,
        private ReportBuilder $reportBuilder,
        private TranscriptMarker $marker,
        private SpeechComparer $comparer,
    ) {
    }

    /**
     * @return array{speeches: list<array{id: string, title: string, createdAt: string, status: string, durationSeconds: float|null, fillerCount: int|null, fillersPerMinute: float|null}>}
     */
    #[McpTool(
        name: 'list_speeches',
        title: 'List Speeches',
        description: 'Lists the most recent recorded Speeches, newest first, with their status and, once processed ("done"), their Filler count and Fillers per minute. Use the IDs with the other Ah Counter tools.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function listSpeeches(
        #[Schema(description: 'How many Speeches to list (default 10; values above 50 are capped at 50).', minimum: 1)]
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $speeches = $this->speeches->recent(max(1, min($limit, self::MAX_LIMIT)));

        return ['speeches' => array_map($this->listItem(...), $speeches)];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_speech_report',
        title: 'Get Speech Report',
        description: 'The Report of one processed Speech: duration, total Fillers, Fillers per minute, counts by Filler Type, Filler Sound and Filler Phrase subtotals, the Coaching, and the Transcript with each counted Filler in [brackets].',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function getSpeechReport(
        #[Schema(description: 'The Speech ID (a UUID, as list_speeches gives it).', format: 'uuid')]
        string $speechId,
    ): array {
        $speech = $this->speeches->done($speechId);
        $report = $this->reportBuilder->build($speech);
        $transcript = $speech->transcript() ?? new Transcript([], $report->durationSeconds);

        // One Fillers-per-minute value, rounded as the Report page shows it.
        $statistics = $report->toArray();
        $statistics['fillersPerMinute'] = $statistics['fillersPerMinuteRounded'];
        unset($statistics['fillersPerMinuteRounded']);

        return [
            'id' => $speech->getId()->toRfc4122(),
            'title' => $speech->getDisplayTitle(),
            'createdAt' => $speech->getCreatedAt()->format(\DATE_ATOM),
            ...$statistics,
            'transcript' => $this->marker->bracketed($transcript, $speech->fillerOccurrences() ?? []),
        ];
    }

    /**
     * @return array{speechId: string, fillers: list<array{type: string, kind: string, start: float, end: float, context: string, verdict: string, reason: string|null}>}
     */
    #[McpTool(
        name: 'list_fillers',
        title: 'List Fillers',
        description: 'Every Filler counted in one processed Speech, in time order: its Filler Type, whether it is a Filler Sound ("sound") or Filler Phrase ("phrase"), start and end in seconds, and about six words of context with the Filler in [brackets]. With includeRejected, also the Candidates the AI judge decided were genuine uses of the word ("not_filler") or left unjudged, with its reason.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function listFillers(
        #[Schema(description: 'The Speech ID (a UUID, as list_speeches gives it).', format: 'uuid')]
        string $speechId,
        #[Schema(description: 'Also list Candidates that were not counted as Fillers (default false).')]
        bool $includeRejected = false,
    ): array {
        $speech = $this->speeches->done($speechId);
        $transcript = $speech->transcript() ?? new Transcript([], (float) $speech->getDurationSeconds());

        $fillers = [];
        foreach ($speech->fillerOccurrences() ?? [] as $occurrence) {
            if (!$includeRejected && !$occurrence->isFiller()) {
                continue;
            }
            $fillers[] = [
                'type' => $occurrence->type,
                'kind' => $occurrence->kind->value,
                'start' => $occurrence->start,
                'end' => $occurrence->end,
                'context' => $this->marker->contextText($transcript, $occurrence, self::CONTEXT_WORDS),
                'verdict' => $occurrence->verdict->value,
                'reason' => $occurrence->reason,
            ];
        }

        return ['speechId' => $speech->getId()->toRfc4122(), 'fillers' => $fillers];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'compare_speeches',
        title: 'Compare Speeches',
        description: 'Compares a later Speech with an earlier baseline Speech: both durations and Fillers per minute, and for every Filler Type the counts, the rates and the change in rate. Every change is later minus baseline, so a negative change is an improvement. mostImproved and mostWorsened name the Filler Types whose rate fell or rose most (null when none did). Plain arithmetic, no AI.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function compareSpeeches(
        #[Schema(description: 'The earlier Speech to compare against (a UUID).', format: 'uuid')]
        string $baselineSpeechId,
        #[Schema(description: 'The later Speech (a UUID).', format: 'uuid')]
        string $speechId,
    ): array {
        if (strtolower($baselineSpeechId) === strtolower($speechId)) {
            throw new ToolCallException('baselineSpeechId and speechId are the same Speech. Pick two different Speeches to compare.');
        }

        $baseline = $this->speeches->done($baselineSpeechId);
        $speech = $this->speeches->done($speechId);
        $comparison = $this->comparer->compare($this->reportBuilder->build($baseline), $this->reportBuilder->build($speech))->toArray();

        return [
            ...$comparison,
            'baseline' => ['id' => $baseline->getId()->toRfc4122(), 'title' => $baseline->getDisplayTitle(), ...$comparison['baseline']],
            'speech' => ['id' => $speech->getId()->toRfc4122(), 'title' => $speech->getDisplayTitle(), ...$comparison['speech']],
        ];
    }

    /**
     * @return array{id: string, title: string, createdAt: string, status: string, durationSeconds: float|null, fillerCount: int|null, fillersPerMinute: float|null}
     */
    private function listItem(Speech $speech): array
    {
        $report = $this->reportBuilder->buildIfDone($speech);

        return [
            'id' => $speech->getId()->toRfc4122(),
            'title' => $speech->getDisplayTitle(),
            'createdAt' => $speech->getCreatedAt()->format(\DATE_ATOM),
            'status' => $speech->getStatus()->value,
            'durationSeconds' => $speech->getDurationSeconds(),
            'fillerCount' => $report?->totalFillers,
            'fillersPerMinute' => $report?->fillersPerMinuteRounded,
        ];
    }
}
