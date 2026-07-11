<?php declare(strict_types=1);

namespace Splac\Core\Content\Template;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Splac\Core\Content\Process\ProcessDefinition;

class TemplateDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'splac_template';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TemplateEntity::class;
    }

    public function getCollectionClass(): string
    {
        return TemplateCollection::class;
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
            // {"de-DE": "<html with {{placeholders}}>", "en-GB": "..."}
            new JsonField('description_templates', 'descriptionTemplates', [], []),
            // feature toggles, per-field generation modes, product number pattern, defaults
            new JsonField('config', 'config', [], []),
            new OneToManyAssociationField('processes', ProcessDefinition::class, 'template_id'),
        ]);
    }
}
