<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Coaching\Coaching;
use App\Entity\Speech;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\RecreatesDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Runs bin/console mcp:server --env=test as a real subprocess and talks JSON-RPC over its STDIN
 * and STDOUT, the way Claude Code and Claude Desktop do (SPEC §10, ticket 18).
 *
 * Every line on STDOUT must be JSON-RPC: a log line or deprecation notice there would break the
 * client, and the SDK's InMemoryTransport could never show it.
 */
final class McpServerEndToEndTest extends KernelTestCase
{
    use RecreatesDatabase;

    private const float TIMEOUT_SECONDS = 20.0;

    private Process $server;
    private InputStream $input;
    private string $buffer = '';

    /** @var list<string> every line the server wrote to STDOUT */
    private array $lines = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::recreateDatabase();

        $php = (new PhpExecutableFinder())->find(false);
        self::assertIsString($php);

        $this->input = new InputStream();
        $this->server = new Process(
            [$php, 'bin/console', 'mcp:server', '--env=test'],
            self::$kernel?->getProjectDir(),
            // Normal verbosity, as a client starts it: a quiet shell could hide console output.
            ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'SHELL_VERBOSITY' => '0'],
            $this->input,
            self::TIMEOUT_SECONDS * 2,
        );
    }

    protected function tearDown(): void
    {
        if ($this->server->isRunning()) {
            $this->server->stop(1);
        }

        parent::tearDown();
    }

    public function testAnMcpClientCanListTheToolsAndReadAReport(): void
    {
        $speech = $this->createDoneSpeech();
        $this->server->start();

        $initialize = $this->request(1, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
        ]);
        self::assertSame('ah-counter', $initialize['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $initialize['result']['capabilities']);
        $this->notify('notifications/initialized');

        $tools = $this->request(2, 'tools/list');
        $names = array_column($tools['result']['tools'], 'name');
        sort($names);
        self::assertSame(['compare_speeches', 'generate_practice_exercise', 'get_speech_report', 'list_fillers', 'list_speeches'], $names);

        $report = $this->request(3, 'tools/call', ['name' => 'get_speech_report', 'arguments' => ['speechId' => $speech->getId()->toRfc4122()]]);
        self::assertFalse($report['result']['isError'] ?? false);
        $structured = $report['result']['structuredContent'];
        self::assertSame(3, $structured['totalFillers']);
        self::assertSame(6.0, (float) $structured['fillersPerMinute']);
        self::assertSame(['um' => 2, 'like' => 1], $structured['byType']);
        self::assertSame('So I [um], think it was [like] huge and you know [um] done.', $structured['transcript']);
        self::assertSame('text', $report['result']['content'][0]['type']);
        self::assertSame($structured, json_decode($report['result']['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR), 'the text content carries the same Report');

        $unknown = $this->request(4, 'tools/call', ['name' => 'get_speech_report', 'arguments' => ['speechId' => Uuid::v4()->toRfc4122()]]);
        self::assertTrue($unknown['result']['isError']);
        self::assertStringContainsString('There is no Speech with ID', $unknown['result']['content'][0]['text']);

        $this->input->close();
        $this->server->wait();
        $this->readLines();

        self::assertSame(4, \count($this->lines), 'one line per request, nothing else: '.implode("\n", $this->lines));
        foreach ($this->lines as $line) {
            $message = json_decode($line, true);
            self::assertIsArray($message, 'not JSON: '.$line);
            self::assertSame('2.0', $message['jsonrpc'] ?? null, 'not JSON-RPC: '.$line);
        }
        self::assertSame(0, $this->server->getExitCode(), $this->server->getErrorOutput());
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the response with this id
     */
    private function request(int $id, string $method, array $params = []): array
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params]);

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $seen = \count($this->lines);
        while (microtime(true) < $deadline) {
            $this->readLines();
            for ($i = $seen; $i < \count($this->lines); ++$i) {
                $message = json_decode($this->lines[$i], true);
                self::assertIsArray($message, 'the server wrote a line that is not JSON: '.$this->lines[$i]);
                if (($message['id'] ?? null) === $id) {
                    self::assertArrayNotHasKey('error', $message, $method.' failed: '.$this->lines[$i]);

                    return $message;
                }
            }
            $seen = \count($this->lines);
            if (!$this->server->isRunning()) {
                self::fail(\sprintf("mcp:server exited (%s) before answering %s.\nSTDERR: %s", $this->server->getExitCode(), $method, $this->server->getErrorOutput()));
            }
            usleep(20_000);
        }

        self::fail(\sprintf("No answer to %s within %.0f s.\nSTDERR: %s", $method, self::TIMEOUT_SECONDS, $this->server->getErrorOutput()));
    }

    private function notify(string $method): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function send(array $message): void
    {
        $this->input->write(json_encode($message, \JSON_THROW_ON_ERROR)."\n");
    }

    private function readLines(): void
    {
        $this->buffer .= $this->server->getIncrementalOutput();
        while (false !== $end = strpos($this->buffer, "\n")) {
            $line = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 1);
            if ('' !== trim($line)) {
                $this->lines[] = $line;
            }
        }
    }

    /**
     * "So I um, think it was like huge and you know um done.", 30 s long: um ×2 and a judged
     * "like" count; "you know" was rejected.
     */
    private function createDoneSpeech(): Speech
    {
        $speech = new Speech(Uuid::v4(), 'speech.webm', 'audio/webm', 30.0, 'End-to-end');
        $speech->recordTranscript(FakeTranscriber::transcript(['So', 'I', 'um,', 'think', 'it', 'was', 'like', 'huge', 'and', 'you', 'know', 'um', 'done.'], 30.0));
        $speech->recordFillers([
            new FillerOccurrence('um', FillerKind::Sound, 2.0, 2.5, 2, 2, Verdict::Filler),
            new FillerOccurrence('like', FillerKind::Phrase, 6.0, 6.5, 6, 6, Verdict::Filler, 'stands in for a pause'),
            new FillerOccurrence('you know', FillerKind::Phrase, 9.0, 10.5, 9, 10, Verdict::NotFiller, 'asks the listener'),
            new FillerOccurrence('um', FillerKind::Sound, 11.0, 11.5, 11, 11, Verdict::Filler),
        ]);
        $speech->recordCoaching(new Coaching('Good pace.', [], ['Pause instead of saying um.']));
        $speech->markDone();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->persist($speech);
        $entityManager->flush();

        return $speech;
    }
}
