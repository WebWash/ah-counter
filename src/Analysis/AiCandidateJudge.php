<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Ai\ClaudeClient;
use App\Transcription\Transcript;
use App\Transcription\Word;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Judges every Candidate of a Speech in one structured-output call to AI_JUDGE_MODEL (Haiku,
 * SPEC §6.3).
 *
 * The model sees the whole Transcript with each Candidate marked inline, e.g. "it was
 * [[c2:like,]] huge", and answers with one verdict per marker ID. Unknown IDs are ignored, the
 * first of two verdicts for the same ID wins, and a Candidate left out stays unjudged.
 */
#[AsAlias(CandidateJudge::class)]
final readonly class AiCandidateJudge implements CandidateJudge
{
    private const string INSTRUCTIONS = <<<'PROMPT'
        You are the Ah Counter at a Toastmasters club meeting. You judge possible filler words in the transcript of one short English speech.

        Some words in the transcript are marked like [[c7:like,]]: an ID, a colon, then the word or phrase exactly as spoken, with its punctuation. Judge only the marked items. The other words, including sounds such as "um" and "uh", are context only.

        For each marked item, decide whether it is a filler: a word or phrase that contributes no meaning in that sentence and stands in for a pause or a hesitation. Examples of fillers: "it was, like, huge"; "So, um, yeah" opening a thought with nothing to connect; "you know" as a verbal tic; "I mean" or "basically" that adds nothing. Examples that are not fillers: "I like coffee"; "it looks like rain"; "so that we can"; "it was so loud"; "do you know the way?"; "what I mean is this"; "the right answer"; "well done"; "I actually finished early".

        When you are unsure, prefer not filler: counting a real word as a filler discourages the speaker more than missing one.

        Answer with exactly one verdict per marked ID, using the bare ID (for example "c7"). Keep each reason to one short sentence that refers to the words around the item.
        PROMPT;

    /**
     * About 50 tokens per verdict, so even a 5-minute Speech with a hundred Candidates fits with
     * room left for the model's thinking.
     */
    private const int MAX_OUTPUT_TOKENS = 16000;

    public function __construct(
        private ClaudeClient $claude,
        #[Autowire(env: 'AI_JUDGE_MODEL')]
        private string $model,
        private LoggerInterface $logger,
    ) {
    }

    public function judge(Transcript $transcript, array $occurrences): array
    {
        $ids = $this->markerIds($occurrences);
        if ([] === $ids) {
            return $occurrences;
        }

        $answer = $this->claude->ask(
            $this->model,
            new MessageBag(
                Message::forSystem(self::INSTRUCTIONS),
                Message::ofUser("Transcript:\n\n".$this->markedText($transcript, $occurrences, $ids)),
            ),
            CandidateVerdicts::class,
            self::MAX_OUTPUT_TOKENS,
        );
        $verdicts = $this->firstVerdictPerKnownId($answer, $ids);

        $judged = [];
        foreach ($occurrences as $index => $occurrence) {
            $id = $ids[$index] ?? null;
            $verdict = null === $id ? null : ($verdicts[$id] ?? null);

            if (null !== $id && null === $verdict) {
                $this->logger->warning('The Candidate judge left {id} ("{type}" at {start} s) unjudged.', ['id' => $id, 'type' => $occurrence->type, 'start' => $occurrence->start]);
            }

            $judged[] = null === $verdict ? $occurrence : new FillerOccurrence(
                $occurrence->type,
                $occurrence->kind,
                $occurrence->start,
                $occurrence->end,
                $occurrence->wordStart,
                $occurrence->wordEnd,
                $verdict->isFiller ? Verdict::Filler : Verdict::NotFiller,
                $verdict->reason,
            );
        }

        return $judged;
    }

    /**
     * @param list<FillerOccurrence> $occurrences
     *
     * @return array<int, string> index in $occurrences => marker ID ("c1", "c2", …), Candidates only
     */
    private function markerIds(array $occurrences): array
    {
        $ids = [];
        foreach ($occurrences as $index => $occurrence) {
            if (FillerKind::Phrase === $occurrence->kind && Verdict::Unjudged === $occurrence->verdict) {
                $ids[$index] = 'c'.(\count($ids) + 1);
            }
        }

        return $ids;
    }

    /**
     * The punctuated Transcript with each Candidate's words wrapped in its marker.
     *
     * @param list<FillerOccurrence> $occurrences
     * @param array<int, string>     $ids
     */
    private function markedText(Transcript $transcript, array $occurrences, array $ids): string
    {
        $markerAt = [];
        foreach ($ids as $index => $id) {
            $markerAt[$occurrences[$index]->wordStart] = [$id, $occurrences[$index]->wordEnd];
        }

        $parts = [];
        $count = \count($transcript->words);
        for ($i = 0; $i < $count; ++$i) {
            if (!isset($markerAt[$i])) {
                $parts[] = $transcript->words[$i]->punctuated;

                continue;
            }

            [$id, $last] = $markerAt[$i];
            $phrase = array_map(static fn (Word $word): string => $word->punctuated, \array_slice($transcript->words, $i, $last - $i + 1));
            $parts[] = \sprintf('[[%s:%s]]', $id, implode(' ', $phrase));
            $i = $last;
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int, string> $ids
     *
     * @return array<string, CandidateVerdict> marker ID => the first verdict given for it
     */
    private function firstVerdictPerKnownId(CandidateVerdicts $answer, array $ids): array
    {
        $known = array_flip($ids);
        $verdicts = [];
        foreach ($answer->verdicts as $verdict) {
            if (!isset($known[$verdict->id])) {
                $this->logger->info('The Candidate judge answered for unknown ID {id}; ignored.', ['id' => $verdict->id]);

                continue;
            }
            $verdicts[$verdict->id] ??= $verdict;
        }

        return $verdicts;
    }
}
