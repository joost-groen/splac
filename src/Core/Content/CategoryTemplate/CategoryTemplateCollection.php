<?php declare(strict_types=1);

namespace Splac\Core\Content\CategoryTemplate;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<CategoryTemplateEntity>
 */
class CategoryTemplateCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return CategoryTemplateEntity::class;
    }
}
