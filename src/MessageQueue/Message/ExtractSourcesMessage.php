<?php declare(strict_types=1);

namespace Splac\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class ExtractSourcesMessage implements AsyncMessageInterface
{
    public function __construct(
        public readonly string $processId,
    ) {
    }
}
