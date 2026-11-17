<?php

declare(strict_types=1);

namespace App\Tests\AccuracyCheck;

use App\AccuracyCheck\AccuracyRecording;
use App\AccuracyCheck\WhisperAccuracyTranscriber;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WhisperAccuracyTranscriberTest extends TestCase
{
    private const array VERBOSE_RESPONSE = [
        'task' => 'transcribe',
        'language' => 'english',
        'duration' => 3.2,
        'text' => 'Um, so I, er, think.',
        'words' => [
            ['word' => 'Um', 'start' => 0.1, 'end' => 0.4],
            ['word' => 'so', 'start' => 0.5, 'end' => 0.7],
            ['word' => 'I', 'start' => 0.8, 'end' => 0.9],
            ['word' => 'er', 'start' => 1.0, 'end' => 1.3],
            ['word' => 'think', 'start' => 1.4, 'end' => 1.8],
        ],
    ];

    private string $audioPath;

    private string $sentBody = '';

    protected function setUp(): void
    {
        $this->audioPath = sys_get_temp_dir().'/clip-'.bin2hex(random_bytes(4)).'.webm';
        file_put_contents($this->audioPath, 'fake webm bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->audioPath);
    }

    public function testAsksForWordTimestampsWithAFillerRichPromptAndReadsEveryWord(): void
    {
        $transcription = $this->transcriber(new JsonMockResponse(self::VERBOSE_RESPONSE))->transcribe($this->recording());

        self::assertSame(['Um', 'so', 'I', 'er', 'think'], $transcription->words);
        self::assertSame('Um, so I, er, think.', $transcription->text);
        self::assertSame(self::VERBOSE_RESPONSE, $transcription->response);

        self::assertMatchesRegularExpression('/name="model"\s+whisper-1/', $this->sentBody);
        self::assertMatchesRegularExpression('/name="response_format"\s+verbose_json/', $this->sentBody);
        self::assertMatchesRegularExpression('/name="timestamp_granularities\[\]"\s+word/', $this->sentBody);
        self::assertMatchesRegularExpression('/name="prompt"\s+Umm,/', $this->sentBody);
        self::assertStringContainsString('fake webm bytes', $this->sentBody);
    }

    public function testReportsAnHttpErrorWithOpenAisMessage(): void
    {
        $unauthorised = new JsonMockResponse(['error' => ['message' => 'Incorrect API key provided.']], ['http_code' => 401]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->transcriber($unauthorised)->transcribe($this->recording());
    }

    /**
     * The bundle registers the structured-output PlatformSubscriber, which in ai-platform
     * 1.0.0-BETA1 breaks getResult() whenever a "response_format" option is set.
     */
    private function transcriber(MockResponse $response): WhisperAccuracyTranscriber
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $body = $options['body'];
            if ($body instanceof \Closure) {
                while ('' !== $chunk = $body(8192)) {
                    $this->sentBody .= $chunk;
                }
            } else {
                $this->sentBody = (string) $body;
            }

            return $response;
        });

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());

        return new WhisperAccuracyTranscriber(Factory::createPlatform('sk-test', $http, eventDispatcher: $dispatcher));
    }

    private function recording(): AccuracyRecording
    {
        return new AccuracyRecording('clip-1', $this->audioPath, 'audio/webm', null);
    }
}
