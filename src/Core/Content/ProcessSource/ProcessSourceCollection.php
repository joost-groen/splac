<?php declare(strict_types=1);

namespace Splac\Core\Content\ProcessSource;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ProcessSourceEntity>
 */
class ProcessSourceCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ProcessSourceEntity::class;
    }
}
