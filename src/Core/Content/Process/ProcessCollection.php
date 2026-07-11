<?php declare(strict_types=1);

namespace Splac\Core\Content\Process;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ProcessEntity>
 */
class ProcessCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ProcessEntity::class;
    }
}
