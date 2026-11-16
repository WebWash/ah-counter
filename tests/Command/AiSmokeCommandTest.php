<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AiSmokeCommand;
use App\Smoke\FillerCount;
use App\Smoke\FillerTally;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AiSmokeCommandTest extends TestCase
{
    public function testPrintsTheStructuredTallyReturnedByTheJudgeModel(): void
    {
        $requests = [];
        $anthropic = MockPlatformFactory::createPlatform(
            static function ($model, $payload, array $options) use (&$requests): ObjectResult {
                $requests[] = ['model' => $model->getName(), 'options' => $options];

                return new ObjectResult(new FillerTally(
                    fillers: [new FillerCount('um', 2), new FillerCount('you know', 1)],
                    total: 3,
                ));
            },
        );

        $tester = new CommandTester(new AiSmokeCommand($anthropic, MockPlatformFactory::createPlatform('unused'), 'claude-haiku-5-5', 'nova-3'));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertCount(1, $requests);
        self::assertSame('claude-haiku-5-5', $requests[0]['model']);
        self::assertSame(FillerTally::class, $requests[0]['options']['response_format']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('um', $display);
        self::assertStringContainsString('you know', $display);
        self::assertMatchesRegularExpression('/Total\D+3/', $display);
        self::assertStringContainsString('Anthropic OK', $display);
    }

    public function testReportsAProviderFailureReadablyAndFails(): void
    {
        $anthropic = MockPlatformFactory::createPlatform(
            static fn () => throw new AuthenticationException('invalid x-api-key'),
        );

        $tester = new CommandTester(new AiSmokeCommand($anthropic, MockPlatformFactory::createPlatform('unused'), 'claude-haiku-5-5', 'nova-3'));
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('invalid x-api-key', $tester->getDisplay());
        self::assertStringContainsString('ANTHROPIC_API_KEY', $tester->getDisplay());
    }
}
