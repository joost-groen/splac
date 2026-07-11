<?php declare(strict_types=1);

namespace Splac\Core\Content\ProcessSource;

use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Splac\Core\Content\Process\ProcessEntity;

class ProcessSourceEntity extends Entity
{
    use EntityIdTrait;

    protected string $processId;

    protected ?string $mediaId = null;

    protected string $filename;

    protected string $status;

    protected ?string $extractedText = null;

    protected ?string $errorMessage = null;

    protected ?ProcessEntity $process = null;

    protected ?MediaEntity $media = null;

    public function getProcessId(): string
    {
        return $this->processId;
    }

    public function setProcessId(string $processId): void
    {
        $this->processId = $processId;
    }

    public function getMediaId(): ?string
    {
        return $this->mediaId;
    }

    public function setMediaId(?string $mediaId): void
    {
        $this->mediaId = $mediaId;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): void
    {
        $this->filename = $filename;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getExtractedText(): ?string
    {
        return $this->extractedText;
    }

    public function setExtractedText(?string $extractedText): void
    {
        $this->extractedText = $extractedText;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getProcess(): ?ProcessEntity
    {
        return $this->process;
    }

    public function setProcess(?ProcessEntity $process): void
    {
        $this->process = $process;
    }

    public function getMedia(): ?MediaEntity
    {
        return $this->media;
    }

    public function setMedia(?MediaEntity $media): void
    {
        $this->media = $media;
    }
}
