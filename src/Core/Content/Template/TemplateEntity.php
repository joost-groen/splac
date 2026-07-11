<?php declare(strict_types=1);

namespace Splac\Core\Content\Template;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Splac\Core\Content\Process\ProcessCollection;

class TemplateEntity extends Entity
{
    use EntityIdTrait;

    protected ?ProcessCollection $processes = null;

    protected string $name;

    protected bool $active = true;

    /**
     * @var array<string, string>|null map of locale => description HTML with placeholders
     */
    protected ?array $descriptionTemplates = null;

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

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * @return array<string, string>|null
     */
    public function getDescriptionTemplates(): ?array
    {
        return $this->descriptionTemplates;
    }

    /**
     * @param array<string, string>|null $descriptionTemplates
     */
    public function setDescriptionTemplates(?array $descriptionTemplates): void
    {
        $this->descriptionTemplates = $descriptionTemplates;
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

    public function getProcesses(): ?ProcessCollection
    {
        return $this->processes;
    }

    public function setProcesses(ProcessCollection $processes): void
    {
        $this->processes = $processes;
    }
}
