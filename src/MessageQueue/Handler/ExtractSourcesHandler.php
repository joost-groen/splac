<?php declare(strict_types=1);

namespace Splac\MessageQueue\Handler;

use Shopware\Core\Content\Media\File\FileLoader;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Splac\Core\Content\Process\ProcessDefinition;
use Splac\Core\Content\Process\ProcessEntity;
use Splac\Core\Content\ProcessSource\ProcessSourceDefinition;
use Splac\Core\Content\ProcessSource\ProcessSourceEntity;
use Splac\MessageQueue\Message\ExtractSourcesMessage;
use Splac\MessageQueue\Message\GenerateProcessMessage;
use Splac\Service\Llm\LlmException;
use Splac\Service\Llm\LlmService;
use Splac\Service\PdfTextExtractor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class ExtractSourcesHandler
{
    private const PDF_EXTRACTION_MODE_CONFIG = 'Splac.config.pdfExtractionMode';

    private const PDF_EXTRACTION_MODE_AUTOMATIC = 'automatic';

    private const PDF_EXTRACTION_MODE_PROVIDER = 'provider';

    private const PDF_EXTRACTION_MODE_LOCAL = 'local';

    public function __construct(
        private readonly EntityRepository $processRepository,
        private readonly EntityRepository $processSourceRepository,
        private readonly FileLoader $fileLoader,
        private readonly SystemConfigService $systemConfig,
        private readonly LlmService $llmService,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ExtractSourcesMessage $message): void
    {
        $context = Context::createDefaultContext();

        $criteria = new Criteria([$message->processId]);
        $criteria->addAssociation('sources');

        /** @var ProcessEntity|null $process */
        $process = $this->processRepository->search($criteria, $context)->first();
        if ($process === null || $process->getStatus() === ProcessDefinition::STATUS_CANCELLED) {
            return;
        }

        $this->processRepository->update([[
            'id' => $process->getId(),
            'status' => ProcessDefinition::STATUS_EXTRACTING,
            'errorMessage' => null,
        ]], $context);

        $failed = 0;
        $sources = $process->getSources();

        if ($sources !== null) {
            /** @var ProcessSourceEntity $source */
            foreach ($sources as $source) {
                if ($source->getStatus() === ProcessSourceDefinition::STATUS_DONE) {
                    continue;
                }

                try {
                    if ($source->getMediaId() === null) {
                        throw new \RuntimeException('Source has no media file');
                    }

                    $content = $this->fileLoader->loadMediaFile($source->getMediaId(), $context);
                    $text = $this->extractPdfText($content, $source->getFilename());

                    $this->processSourceRepository->update([[
                        'id' => $source->getId(),
                        'status' => ProcessSourceDefinition::STATUS_DONE,
                        'extractedText' => $text,
                        'errorMessage' => null,
                    ]], $context);
                } catch (\Throwable $e) {
                    ++$failed;
                    $this->processSourceRepository->update([[
                        'id' => $source->getId(),
                        'status' => ProcessSourceDefinition::STATUS_FAILED,
                        'errorMessage' => $e->getMessage(),
                    ]], $context);
                }
            }
        }

        if ($failed > 0) {
            $this->processRepository->update([[
                'id' => $process->getId(),
                'status' => ProcessDefinition::STATUS_FAILED,
                'errorMessage' => \sprintf('%d source file(s) could not be extracted', $failed),
            ]], $context);

            return;
        }

        $this->messageBus->dispatch(new GenerateProcessMessage($process->getId()));
    }

    private function extractPdfText(string $content, string $filename): string
    {
        $mode = (string) ($this->systemConfig->get(self::PDF_EXTRACTION_MODE_CONFIG)
            ?? self::PDF_EXTRACTION_MODE_AUTOMATIC);

        if ($mode === self::PDF_EXTRACTION_MODE_LOCAL) {
            return $this->extractPdfLocally($content);
        }

        if ($mode === self::PDF_EXTRACTION_MODE_PROVIDER) {
            return $this->llmService->ocrPdf($content, $filename);
        }

        try {
            return $this->llmService->ocrPdf($content, $filename);
        } catch (LlmException $providerError) {
            try {
                return $this->extractPdfLocally($content);
            } catch (\Throwable $fallbackError) {
                throw new LlmException(
                    $providerError->getMessage() . '; local PDF extraction also failed: ' . $fallbackError->getMessage(),
                    0,
                    $providerError
                );
            }
        }
    }

    private function extractPdfLocally(string $content): string
    {
        $text = $this->pdfTextExtractor->extract($content);
        if (trim($text) === '') {
            throw new \RuntimeException('Local PDF extraction returned no text');
        }

        return $text;
    }
}
