<?php declare(strict_types=1);

namespace Splac\Core\Content\Process;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Splac\Core\Content\CategoryTemplate\CategoryTemplateEntity;
use Splac\Core\Content\ProcessSource\ProcessSourceCollection;
use Splac\Core\Content\Template\TemplateEntity;

class ProcessEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;

    protected string $status;

    protected ?string $currentStep = null;

    protected string $templateId;

    protected ?string $categoryTemplateId = null;

    protected ?string $productId = null;

    protected ?string $productVersionId = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $input = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $output = null;

    protected ?string $errorMessage = null;

    protected float $llmCost = 0.0;

    protected string $llmCostCurrency = 'EUR';

    protected ?TemplateEntity $template = null;

    protected ?CategoryTemplateEntity $categoryTemplate = null;

    protected ?ProductEntity $product = null;

    protected ?ProcessSourceCollection $sources = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCurrentStep(): ?string
    {
        return $this->currentStep;
    }

    public function setCurrentStep(?string $currentStep): void
    {
        $this->currentStep = $currentStep;
    }

    public function getTemplateId(): string
    {
        return $this->templateId;
    }

    public function setTemplateId(string $templateId): void
    {
        $this->templateId = $templateId;
    }

    public function getCategoryTemplateId(): ?string
    {
        return $this->categoryTemplateId;
    }

    public function setCategoryTemplateId(?string $categoryTemplateId): void
    {
        $this->categoryTemplateId = $categoryTemplateId;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function setProductId(?string $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductVersionId(): ?string
    {
        return $this->productVersionId;
    }

    public function setProductVersionId(?string $productVersionId): void
    {
        $this->productVersionId = $productVersionId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInput(): ?array
    {
        return $this->input;
    }

    /**
     * @param array<string, mixed>|null $input
     */
    public function setInput(?array $input): void
    {
        $this->input = $input;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOutput(): ?array
    {
        return $this->output;
    }

    /**
     * @param array<string, mixed>|null $output
     */
    public function setOutput(?array $output): void
    {
        $this->output = $output;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getLlmCost(): float
    {
        return $this->llmCost;
    }

    public function setLlmCost(float $llmCost): void
    {
        $this->llmCost = $llmCost;
    }

    public function getLlmCostCurrency(): string
    {
        return $this->llmCostCurrency;
    }

    public function setLlmCostCurrency(string $llmCostCurrency): void
    {
        $this->llmCostCurrency = $llmCostCurrency;
    }

    public function getTemplate(): ?TemplateEntity
    {
        return $this->template;
    }

    public function setTemplate(?TemplateEntity $template): void
    {
        $this->template = $template;
    }

    public function getCategoryTemplate(): ?CategoryTemplateEntity
    {
        return $this->categoryTemplate;
    }

    public function setCategoryTemplate(?CategoryTemplateEntity $categoryTemplate): void
    {
        $this->categoryTemplate = $categoryTemplate;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }

    public function getSources(): ?ProcessSourceCollection
    {
        return $this->sources;
    }

    public function setSources(ProcessSourceCollection $sources): void
    {
        $this->sources = $sources;
    }
}
