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
    ) {
    }
}
