<?php

declare(strict_types=1);

namespace App\Ai;

use App\Processing\PermanentFailure;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\MissingModelSupportException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;

/**
 * One structured-output call to Anthropic, with its failures sorted the SPEC §5 way.
 *
 * - Permanent (PermanentFailure): a rejected key (401), a refused request (400, prompt too long,
 *   content filter), an unknown model, or a model without structured output.
 * - Short-lived (MalformedAiOutput): an answer that isn't the requested DTO, including JSON cut
 *   off at the output token limit. (The Anthropic bridge only flags truncated tool calls, so a cut
 *   text answer otherwise surfaces as a vague "Cannot deserialize".)
 * - Everything else, 429 and 5xx included, is rethrown as it is, so Messenger retries it.
 */
final readonly class ClaudeClient
{
    public function __construct(
        #[Autowire(service: 'ai.platform.anthropic')]
        private PlatformInterface $anthropic,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class           a readonly DTO; its typed properties become the JSON schema
     * @param int             $maxOutputTokens room for the answer and the model's thinking. The bridge's
     *                                         default (1000) is too small once thinking counts
     *
     * @return T
     *
     * @throws PermanentFailure
     * @throws MalformedAiOutput
     */
    public function ask(string $model, MessageBag $messages, string $class, int $maxOutputTokens): object
    {
        $deferred = null;
        try {
            $deferred = $this->anthropic->invoke($model, $messages, [
                'response_format' => $class,
                PlatformOptions::MAX_OUTPUT_TOKENS => $maxOutputTokens,
            ]);

            return $deferred->asObject($class);
        } catch (AuthenticationException $e) {
            throw new PermanentFailure('The AI service rejected our API key. Check ANTHROPIC_API_KEY, then press Retry.', \sprintf('Anthropic (%s) rejected the key: %s', $model, $e->getMessage()), $e);
        } catch (BadRequestException|InvalidArgumentException|ModelNotFoundException|MissingModelSupportException $e) {
            throw new PermanentFailure('The AI service couldn\'t process this Speech.', \sprintf('Anthropic (%s) refused the request: %s', $model, $e->getMessage()), $e);
        } catch (UnexpectedResultTypeException|MaxOutputTokensException $e) {
            throw new MalformedAiOutput(\sprintf('Anthropic (%s) did not answer with a %s: %s', $model, $class, $e->getMessage()), 0, $e);
        } catch (RuntimeException $e) {
            if (!$e->getPrevious() instanceof SerializerException && !$e->getPrevious() instanceof \JsonException) {
                throw $e;
            }

            $stopReason = $deferred?->getRawResult()->getData()['stop_reason'] ?? null;
            throw new MalformedAiOutput(\sprintf('Anthropic (%s) answered with JSON that is not a valid %s (stop reason: %s): %s', $model, $class, \is_string($stopReason) ? $stopReason : 'unknown', $e->getPrevious()->getMessage()), 0, $e);
        }
    }
}
