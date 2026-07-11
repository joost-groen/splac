<?php declare(strict_types=1);

namespace Splac\Core\Content\CategoryTemplate;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Splac\Core\Content\Process\ProcessCollection;

class CategoryTemplateEntity extends Entity
{
    use EntityIdTrait;

    protected ?ProcessCollection $processes = null;

    protected ?string $parentCategoryVersionId = null;

    protected string $name;

    protected ?string $parentCategoryId = null;

    protected ?CategoryEntity $parentCategory = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $config = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getParentCategoryId(): ?string
    {
        return $this->parentCategoryId;
    }

    public function setParentCategoryId(?string $parentCategoryId): void
    {
        $this->parentCategoryId = $parentCategoryId;
    }

    public function getParentCategory(): ?CategoryEntity
    {
        return $this->parentCategory;
    }

    public function setParentCategory(?CategoryEntity $parentCategory): void
    {
        $this->parentCategory = $parentCategory;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfig(): ?array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function setConfig(?array $config): void
    {
        $this->config = $config;
    }

    public function getParentCategoryVersionId(): ?string
    {
        return $this->parentCategoryVersionId;
    }

    public function setParentCategoryVersionId(?string $parentCategoryVersionId): void
    {
        $this->parentCategoryVersionId = $parentCategoryVersionId;
    }

    public function getProcesses(): ?ProcessCollection
    {
        return $this->processes;
    }

    public function setProcesses(ProcessCollection $processes): void
    {
        $this->processes = $processes;
    }
}
