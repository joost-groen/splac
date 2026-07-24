<?php declare(strict_types=1);

namespace Splac\Service\Llm;

final class LlmBatchPendingException extends LlmException
{
    public function __construct(
        public readonly string $batchId,
        public readonly int $retryAfterMilliseconds = 15000,
    ) {
        parent::__construct(\sprintf('LLM batch "%s" is still processing', $batchId));
    }
}
