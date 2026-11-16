<?php

declare(strict_types=1);

namespace App\Command;

use App\Smoke\FillerCount;
use App\Smoke\FillerTally;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Makes one real call to each provider so a developer (or a judge) can check their keys.
 * Not used by PHPUnit against the real APIs.
 */
#[AsCommand(name: 'app:ai-smoke', description: 'Check the Anthropic key (and optionally Deepgram) with one real call each')]
final class AiSmokeCommand extends Command
{
    private const string SAMPLE = 'So, um, I was, like, thinking that, uh, you know, we could, um, start the meeting a bit earlier.';

    public function __construct(
        #[Autowire(service: 'ai.platform.anthropic')]
        private readonly PlatformInterface $anthropic,
        #[Autowire(service: 'ai.platform.deepgram')]
        private readonly PlatformInterface $deepgram,
        #[Autowire(env: 'AI_JUDGE_MODEL')]
        private readonly string $judgeModel,
        #[Autowire(env: 'DEEPGRAM_MODEL')]
        private readonly string $deepgramModel,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Path to a short audio file to transcribe with Deepgram')]
        ?string $deepgram = null,
    ): int {
        $ok = $this->checkAnthropic($io);

        if (null !== $deepgram) {
            $ok = $this->checkDeepgram($io, $deepgram) && $ok;
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function checkAnthropic(SymfonyStyle $io): bool
    {
        $io->section(\sprintf('Anthropic (%s, structured output)', $this->judgeModel));

        try {
            $tally = $this->anthropic->invoke(
                $this->judgeModel,
                new MessageBag(
                    Message::forSystem('Count the filler words (um, uh, like, you know, ...) in the sentence the user gives you. Group them by lower-case word.'),
                    Message::ofUser(self::SAMPLE),
                ),
                ['response_format' => FillerTally::class],
            )->asObject(FillerTally::class);
        } catch (\Throwable $e) {
            $io->error([
                'Anthropic call failed: '.$e->getMessage(),
                'Check ANTHROPIC_API_KEY in .env.local and AI_JUDGE_MODEL.',
            ]);

            return false;
        }

        $io->text('Sentence: '.self::SAMPLE);
        $io->table(
            ['Filler', 'Count'],
            array_map(static fn (FillerCount $f): array => [$f->word, $f->count], $tally->fillers),
        );
        $io->text(\sprintf('Total: %d', $tally->total));
        $io->success('Anthropic OK: response_format, asObject() and the nested DTO list all work.');

        return true;
    }

    private function checkDeepgram(SymfonyStyle $io, string $path): bool
    {
        $io->section(\sprintf('Deepgram (%s, filler_words=true)', $this->deepgramModel));

        if (!is_file($path) || !is_readable($path)) {
            $io->error(\sprintf('Audio file "%s" not found or not readable.', $path));

            return false;
        }

        try {
            $deferred = $this->deepgram->invoke(
                $this->deepgramModel,
                new Audio(static fn (): string => (string) file_get_contents($path), (string) mime_content_type($path), $path),
                ['filler_words' => 'true', 'punctuate' => 'true', 'language' => 'en'],
            );
            // Convert first so HTTP errors surface as platform exceptions (ADR 0001).
            $deferred->getResult();
            $data = $deferred->getRawResult()->getData();
        } catch (\Throwable $e) {
            $io->error([
                'Deepgram call failed: '.$e->getMessage(),
                'Check DEEPGRAM_API_KEY in .env.local and DEEPGRAM_MODEL.',
            ]);

            return false;
        }

        $words = $data['results']['channels'][0]['alternatives'][0]['words'] ?? [];
        $io->text(\sprintf('Duration: %s s, words: %d', $data['metadata']['duration'] ?? '?', \count($words)));
        $io->text('Transcript: '.implode(' ', array_map(static fn (array $w): string => (string) ($w['punctuated_word'] ?? $w['word']), $words)));
        $io->success('Deepgram OK: word-level data read from the raw result.');

        return true;
    }
}
