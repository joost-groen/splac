<?php declare(strict_types=1);

namespace Splac\Core\Content\CategoryTemplate;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Splac\Core\Content\Process\ProcessDefinition;

class CategoryTemplateDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'splac_category_template';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return CategoryTemplateEntity::class;
    }

    public function getCollectionClass(): string
    {
        return CategoryTemplateCollection::class;
    }

    public function since(): ?string
    {
        return '1.0.0';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('name', 'name'))->addFlags(new Required()),
            new BoolField('active', 'active'),
            new FkField('parent_category_id', 'parentCategoryId', CategoryDefinition::class),
            new ReferenceVersionField(CategoryDefinition::class, 'parent_category_version_id'),
            // per-locale generation config for category name/description (instruction or placeholder mode)
            new JsonField('config', 'config', [], []),
            new ManyToOneAssociationField('parentCategory', 'parent_category_id', CategoryDefinition::class, 'id'),
            new OneToManyAssociationField('processes', ProcessDefinition::class, 'category_template_id'),
        ]);
    }
}
