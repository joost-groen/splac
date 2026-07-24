<?php declare(strict_types=1);

namespace Splac\Core\Content\Process;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Splac\Core\Content\CategoryTemplate\CategoryTemplateDefinition;
use Splac\Core\Content\ProcessSource\ProcessSourceDefinition;
use Splac\Core\Content\Template\TemplateDefinition;

class ProcessDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'splac_process';

    final public const STATUS_DRAFT = 'draft';
    final public const STATUS_EXTRACTING = 'extracting';
    final public const STATUS_GENERATING = 'generating';
    final public const STATUS_REVIEW = 'review';
    final public const STATUS_CREATING = 'creating';
    final public const STATUS_DONE = 'done';
    final public const STATUS_FAILED = 'failed';
    final public const STATUS_CANCELLED = 'cancelled';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ProcessEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ProcessCollection::class;
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
            (new StringField('status', 'status'))->addFlags(new Required()),
            new StringField('current_step', 'currentStep'),
            (new FkField('template_id', 'templateId', TemplateDefinition::class))->addFlags(new Required()),
            new FkField('category_template_id', 'categoryTemplateId', CategoryTemplateDefinition::class),
            new FkField('product_id', 'productId', ProductDefinition::class),
            new ReferenceVersionField(ProductDefinition::class),
            // user inputs from the wizard: price, stock, tax, category, sales channels, instructions
            new JsonField('input', 'input', [], []),
            // generated output per field, incl. per-field status/source flags
            new JsonField('output', 'output', [], []),
            new LongTextField('error_message', 'errorMessage'),
            new FloatField('llm_cost', 'llmCost'),
            new StringField('llm_cost_currency', 'llmCostCurrency'),
            new ManyToOneAssociationField('template', 'template_id', TemplateDefinition::class, 'id'),
            new ManyToOneAssociationField('categoryTemplate', 'category_template_id', CategoryTemplateDefinition::class, 'id'),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id'),
            (new OneToManyAssociationField('sources', ProcessSourceDefinition::class, 'process_id'))->addFlags(new CascadeDelete()),
        ]);
    }
}
