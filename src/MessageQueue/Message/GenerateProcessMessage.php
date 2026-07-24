<?php declare(strict_types=1);

namespace Splac\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class GenerateProcessMessage implements AsyncMessageInterface
{
    public function __construct(
        public readonly string $processId,
        /**
         * When set, only this single output field is regenerated
         * (description, seo, properties, classification, category).
         */
        public readonly ?string $onlyStep = null,
        /**
         * Anthropic Message Batch ID while an asynchronous step is in flight.
         */
        public readonly ?string $batchId = null,
        /**
         * @var list<string>
         *
         * Steps to continue after an asynchronous batch step completes.
         */
        public readonly array $remainingSteps = [],
        /**
         * Retry the step using adaptive thinking after an explicit provider rejection.
         */
        public readonly bool $forceAdaptiveThinking = false,
    ) {
    }
}
