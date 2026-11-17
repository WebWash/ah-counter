<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Repository\SpeechRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * How every MCP tool loads Speeches (SPEC §8).
 *
 * mcp:server is one long-running process while the worker updates Speeches from another, so each
 * load starts by clearing Doctrine's identity map: otherwise a Speech read once as "transcribing"
 * would stay that way. Anything the caller should read becomes a ToolCallException, which the
 * client receives as a tool error (isError: true) instead of a bare internal error.
 */
final readonly class McpSpeeches
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpeechRepository $speeches,
    ) {
    }

    /**
     * @return list<Speech> newest first
     */
    public function recent(int $limit): array
    {
        $this->entityManager->clear();

        return $this->speeches->findRecent($limit);
    }

    /**
     * A done Speech, freshly loaded.
     *
     * @throws ToolCallException when the ID is malformed or unknown, or the Speech isn't done
     */
    public function done(string $speechId): Speech
    {
        if (!Uuid::isValid($speechId)) {
            throw new ToolCallException(\sprintf('"%s" is not a Speech ID. Speech IDs are UUIDs; list_speeches shows them.', $speechId));
        }

        $this->entityManager->clear();
        $speech = $this->speeches->find(Uuid::fromString($speechId));
        if (null === $speech) {
            throw new ToolCallException(\sprintf('There is no Speech with ID %s. list_speeches shows the Speeches there are.', $speechId));
        }

        return match ($speech->getStatus()) {
            SpeechStatus::Done => $speech,
            SpeechStatus::Failed => throw new ToolCallException(\sprintf('Speech %s failed: %s', $speechId, $speech->getError() ?? 'no reason was recorded.')),
            SpeechStatus::Pending => throw new ToolCallException(\sprintf('Speech %s is waiting to be processed. Try again in a minute.', $speechId)),
            default => throw new ToolCallException(\sprintf('Speech %s is still %s. Try again in a minute.', $speechId, $speech->getStatus()->value)),
        };
    }
}
