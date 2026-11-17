<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Symfony AI's MockPlatformFactory platform, plus a record of each call's model, messages and
 * options as the adapter passed them, so tests can read the prompt.
 */
final class RecordingPlatform implements PlatformInterface
{
    /** @var list<array{model: string, messages: MessageBag, options: array<string, mixed>}> */
    public array $calls = [];

    private readonly PlatformInterface $inner;

    /**
     * @param ResultInterface|\Closure(): ResultInterface $answer          what every call returns; a closure may throw
     * @param bool                                        $structuredOutput run Symfony AI's structured-output subscriber, so a
     *                                                                      TextResult answer is decoded into the
     *                                                                      response_format DTO like a real model's JSON
     */
    public function __construct(ResultInterface|\Closure $answer, bool $structuredOutput = false)
    {
        $dispatcher = null;
        if ($structuredOutput) {
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new PlatformSubscriber());
        }

        $this->inner = MockPlatformFactory::createPlatform(
            static fn (): ResultInterface => $answer instanceof \Closure ? $answer() : $answer,
            eventDispatcher: $dispatcher,
        );
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        \assert($input instanceof MessageBag);
        $this->calls[] = ['model' => \is_string($model) ? $model : $model->getName(), 'messages' => $input, 'options' => $options];

        return $this->inner->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }

    public function systemPrompt(int $call = 0): string
    {
        $content = $this->calls[$call]['messages']->getSystemMessage()?->getContent() ?? '';

        return \is_string($content) ? $content : '';
    }

    public function userPrompt(int $call = 0): string
    {
        return $this->calls[$call]['messages']->getUserMessage()?->asText() ?? '';
    }
}
