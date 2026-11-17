<?php

declare(strict_types=1);

namespace App\Tests\Transcription;

use App\Processing\PermanentFailure;
use App\Transcription\DeepgramResponseNormaliser;
use App\Transcription\DeepgramTranscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\AI\Platform\Bridge\Deepgram\Factory;

final class DeepgramTranscriberTest extends TestCase
{
    /** @var list<array{url: string, headers: list<string>}> */
    private array $requests = [];

    private string $audioPath;

    protected function setUp(): void
    {
        $this->audioPath = (string) tempnam(sys_get_temp_dir(), 'speech');
        file_put_contents($this->audioPath, 'fake mp4 bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->audioPath);
    }

    public function testTranscribesARecordingKeepingFillerSoundsAndTimestamps(): void
    {
        $fixture = (string) file_get_contents(\dirname(__DIR__).'/Fixtures/deepgram/clip-1.json');

        $transcript = $this->transcriber(new MockResponse($fixture, ['response_headers' => ['content-type' => 'application/json']]))
            ->transcribe($this->audioPath, 'audio/mp4');

        self::assertSame(51.24, $transcript->durationSeconds);
        self::assertCount(77, $transcript->words);
        self::assertSame('uh', $transcript->words[5]->text);
        self::assertGreaterThan(0.0, $transcript->words[5]->start);

        $listen = $this->listenRequest();
        parse_str((string) parse_url($listen['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['model' => 'nova-2', 'filler_words' => 'true', 'punctuate' => 'true', 'language' => 'en'], $query);
        self::assertContains('Content-Type: audio/mp4', $listen['headers'], 'the stored MIME type is sent, not a re-detected one');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function permanentStatuses(): iterable
    {
        yield '400 bad request' => [400];
        yield '401 invalid key' => [401];
        yield '403 forbidden' => [403];
        yield '402 out of credit' => [402];
    }

    #[DataProvider('permanentStatuses')]
    public function testAProviderRefusalIsAPermanentFailure(int $status): void
    {
        $response = new JsonMockResponse(['err_code' => 'NOPE', 'err_msg' => 'Refused.'], ['http_code' => $status]);

        try {
            $this->transcriber($response)->transcribe($this->audioPath, 'audio/webm');
            self::fail('Expected a permanent failure.');
        } catch (PermanentFailure $e) {
            self::assertNotSame('', $e->userMessage);
            self::assertStringNotContainsString('Refused.', $e->userMessage, 'the provider\'s raw message is logged, not shown');
            self::assertStringContainsString((string) $status, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function shortLivedStatuses(): iterable
    {
        yield '429 rate limited' => [429];
        yield '500 server error' => [500];
        yield '503 unavailable' => [503];
        yield '408 request timeout' => [408];
    }

    #[DataProvider('shortLivedStatuses')]
    public function testABusyOrBrokenProviderIsAShortLivedFailure(int $status): void
    {
        $response = new JsonMockResponse(['err_msg' => 'Try later.'], ['http_code' => $status]);

        try {
            $this->transcriber($response)->transcribe($this->audioPath, 'audio/webm');
            self::fail('Expected an exception.');
        } catch (PermanentFailure) {
            self::fail('A short-lived failure must not be permanent: Messenger should retry it.');
        } catch (\Throwable $e) {
            self::addToAssertionCount(1);
        }
    }

    public function testATimeoutIsAShortLivedFailure(): void
    {
        $timeout = new MockResponse('', ['error' => 'Idle timeout reached']);

        try {
            $this->transcriber($timeout)->transcribe($this->audioPath, 'audio/webm');
            self::fail('Expected an exception.');
        } catch (PermanentFailure) {
            self::fail('A timeout must not be permanent.');
        } catch (\Throwable) {
            self::addToAssertionCount(1);
        }
    }

    public function testAMissingRecordingIsAPermanentFailureWithoutCallingTheProvider(): void
    {
        $this->expectException(PermanentFailure::class);

        try {
            $this->transcriber(new JsonMockResponse([]))->transcribe('/nonexistent/speech.webm', 'audio/webm');
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    private function transcriber(MockResponse $listenResponse): DeepgramTranscriber
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($listenResponse): MockResponse {
            $this->requests[] = ['url' => $url, 'headers' => $options['headers']];

            return str_contains($url, '/models')
                ? new JsonMockResponse(['stt' => [['name' => 'nova-2', 'canonical_name' => 'nova-2']], 'tts' => []])
                : $listenResponse;
        });

        return new DeepgramTranscriber(Factory::createPlatform(apiKey: 'test-key', httpClient: $http), new DeepgramResponseNormaliser(), 'nova-2');
    }

    /**
     * @return array{url: string, headers: list<string>}
     */
    private function listenRequest(): array
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], '/listen')) {
                return $request;
            }
        }

        self::fail('No /listen request was sent.');
    }
}
