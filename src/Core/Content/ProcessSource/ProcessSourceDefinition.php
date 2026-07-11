<?php declare(strict_types=1);

namespace Splac\Core\Content\ProcessSource;

use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Splac\Core\Content\Process\ProcessDefinition;

class ProcessSourceDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'splac_process_source';

    final public const STATUS_PENDING = 'pending';
    final public const STATUS_EXTRACTING = 'extracting';
    final public const STATUS_DONE = 'done';
    final public const STATUS_FAILED = 'failed';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ProcessSourceEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ProcessSourceCollection::class;
    }

    public function since(): ?string
    {
        return '1.0.0';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('process_id', 'processId', ProcessDefinition::class))->addFlags(new Required()),
            new FkField('media_id', 'mediaId', MediaDefinition::class),
            (new StringField('filename', 'filename'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            new LongTextField('extracted_text', 'extractedText'),
            new LongTextField('error_message', 'errorMessage'),
            new ManyToOneAssociationField('process', 'process_id', ProcessDefinition::class, 'id'),
            new ManyToOneAssociationField('media', 'media_id', MediaDefinition::class, 'id'),
        ]);
    }
}
