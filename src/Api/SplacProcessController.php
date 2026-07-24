<?php declare(strict_types=1);

namespace Splac\Api;

use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Splac\Core\Content\Process\ProcessDefinition;
use Splac\Core\Content\Process\ProcessEntity;
use Splac\Core\Content\ProcessSource\ProcessSourceDefinition;
use Splac\MessageQueue\Message\ExtractSourcesMessage;
use Splac\MessageQueue\Message\GenerateProcessMessage;
use Splac\Service\Llm\LlmUsageService;
use Splac\Service\ProcessGenerator;
use Splac\Service\ProductCreator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class SplacProcessController
{
    public function __construct(
        private readonly EntityRepository $processRepository,
        private readonly EntityRepository $processSourceRepository,
        private readonly MediaService $mediaService,
        private readonly MessageBusInterface $messageBus,
        private readonly ProductCreator $productCreator,
        private readonly ProcessGenerator $processGenerator,
        private readonly LlmUsageService $llmUsageService,
    ) {
    }

    #[Route(path: '/api/_action/splac/cost-statistics', name: 'api.action.splac.cost_statistics', methods: ['GET'])]
    public function costStatistics(): JsonResponse
    {
        return new JsonResponse($this->llmUsageService->statistics());
    }

    #[Route(path: '/api/_action/splac/process', name: 'api.action.splac.process.create', methods: ['POST'])]
    public function createProcess(Request $request, Context $context): JsonResponse
    {
        $payload = $request->toArray();

        $templateId = (string) ($payload['templateId'] ?? '');
        if (!Uuid::isValid($templateId)) {
            return new JsonResponse(['message' => 'templateId is required'], Response::HTTP_BAD_REQUEST);
        }

        $categoryTemplateId = (string) ($payload['categoryTemplateId'] ?? '');

        $processId = Uuid::randomHex();
        $this->processRepository->create([[
            'id' => $processId,
            'name' => (string) ($payload['name'] ?? 'New listing'),
            'status' => ProcessDefinition::STATUS_DRAFT,
            'templateId' => $templateId,
            'categoryTemplateId' => Uuid::isValid($categoryTemplateId) ? $categoryTemplateId : null,
            'input' => \is_array($payload['input'] ?? null) ? $payload['input'] : [],
        ]], $context);

        return new JsonResponse(['id' => $processId]);
    }

    #[Route(path: '/api/_action/splac/process/{processId}/source', name: 'api.action.splac.process.upload_source', methods: ['POST'])]
    public function uploadSource(string $processId, Request $request, Context $context): JsonResponse
    {
        $process = $this->loadProcess($processId, $context);
        if ($process === null) {
            return new JsonResponse(['message' => 'Process not found'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return new JsonResponse(['message' => 'No file uploaded (field "file")'], Response::HTTP_BAD_REQUEST);
        }

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'pdf') {
            return new JsonResponse(['message' => 'Only PDF files are supported'], Response::HTTP_BAD_REQUEST);
        }

        $content = (string) file_get_contents($file->getPathname());
        $filename = $file->getClientOriginalName();

        $mediaId = $this->mediaService->saveFile(
            $content,
            'pdf',
            'application/pdf',
            pathinfo($filename, \PATHINFO_FILENAME) . '-' . substr(Uuid::randomHex(), 0, 8),
            $context,
            null,
            null,
            true
        );

        $sourceId = Uuid::randomHex();
        $this->processSourceRepository->create([[
            'id' => $sourceId,
            'processId' => $processId,
            'mediaId' => $mediaId,
            'filename' => $filename,
            'status' => ProcessSourceDefinition::STATUS_PENDING,
        ]], $context);

        return new JsonResponse(['id' => $sourceId, 'mediaId' => $mediaId]);
    }

    #[Route(path: '/api/_action/splac/process/{processId}/start', name: 'api.action.splac.process.start', methods: ['POST'])]
    public function start(string $processId, Request $request, Context $context): JsonResponse
    {
        $process = $this->loadProcess($processId, $context);
        if ($process === null) {
            return new JsonResponse(['message' => 'Process not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->getContent() !== '' ? $request->toArray() : [];
        $update = [
            'id' => $processId,
            'status' => ProcessDefinition::STATUS_EXTRACTING,
            'errorMessage' => null,
        ];
        if (\is_array($payload['input'] ?? null)) {
            $update['input'] = $payload['input'];
        }
        if (!empty($payload['name']) && \is_string($payload['name'])) {
            $update['name'] = $payload['name'];
        }
        $this->processRepository->update([$update], $context);

        $this->messageBus->dispatch(new ExtractSourcesMessage($processId));

        return new JsonResponse(['status' => ProcessDefinition::STATUS_EXTRACTING]);
    }

    #[Route(path: '/api/_action/splac/process/{processId}/regenerate', name: 'api.action.splac.process.regenerate', methods: ['POST'])]
    public function regenerate(string $processId, Request $request, Context $context): JsonResponse
    {
        $process = $this->loadProcess($processId, $context);
        if ($process === null) {
            return new JsonResponse(['message' => 'Process not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        $step = (string) ($payload['step'] ?? '');

        $validSteps = [
            ProcessGenerator::STEP_CLASSIFICATION,
            ProcessGenerator::STEP_DESCRIPTION,
            ProcessGenerator::STEP_SEO,
            ProcessGenerator::STEP_PROPERTIES,
            ProcessGenerator::STEP_CATEGORY,
        ];
        if (!\in_array($step, $validSteps, true)) {
            return new JsonResponse(['message' => 'Invalid step'], Response::HTTP_BAD_REQUEST);
        }

        // Persist any field edits made in the review screen before regenerating.
        if (\is_array($payload['output'] ?? null)) {
            $this->processRepository->update([[
                'id' => $processId,
                'output' => $payload['output'],
            ]], $context);
        }

        $this->messageBus->dispatch(new GenerateProcessMessage($processId, $step));

        return new JsonResponse(['status' => ProcessDefinition::STATUS_GENERATING]);
    }

    #[Route(path: '/api/_action/splac/process/{processId}/approve', name: 'api.action.splac.process.approve', methods: ['POST'])]
    public function approve(string $processId, Request $request, Context $context): JsonResponse
    {
        $process = $this->loadProcess($processId, $context);
        if ($process === null) {
            return new JsonResponse(['message' => 'Process not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->getContent() !== '' ? $request->toArray() : [];

        // Apply the reviewed/edited output before creating the product.
        if (\is_array($payload['output'] ?? null)) {
            $process->setOutput($payload['output']);
        }
        if (\is_array($payload['input'] ?? null)) {
            $process->setInput($payload['input']);
        }

        $this->processRepository->update([[
            'id' => $processId,
            'status' => ProcessDefinition::STATUS_CREATING,
            'input' => $process->getInput(),
            'output' => $process->getOutput(),
            'errorMessage' => null,
        ]], $context);

        try {
            $productId = $this->productCreator->create($process, $context);
        } catch (\Throwable $e) {
            $this->processRepository->update([[
                'id' => $processId,
                'status' => ProcessDefinition::STATUS_FAILED,
                'errorMessage' => 'Product creation failed: ' . $e->getMessage(),
            ]], $context);

            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->processRepository->update([[
            'id' => $processId,
            'status' => ProcessDefinition::STATUS_DONE,
            'productId' => $productId,
            'productVersionId' => Defaults::LIVE_VERSION,
        ]], $context);

        return new JsonResponse(['productId' => $productId]);
    }

    #[Route(path: '/api/_action/splac/process/{processId}/retry', name: 'api.action.splac.process.retry', methods: ['POST'])]
    public function retry(string $processId, Context $context): JsonResponse
    {
        $process = $this->loadProcess($processId, $context);
        if ($process === null) {
            return new JsonResponse(['message' => 'Process not found'], Response::HTTP_NOT_FOUND);
        }

        $this->processRepository->update([[
            'id' => $processId,
            'status' => ProcessDefinition::STATUS_EXTRACTING,
            'errorMessage' => null,
        ]], $context);

        $this->messageBus->dispatch(new ExtractSourcesMessage($processId));

        return new JsonResponse(['status' => ProcessDefinition::STATUS_EXTRACTING]);
    }

    #[Route(path: '/api/_action/splac/process/{processId}/cancel', name: 'api.action.splac.process.cancel', methods: ['POST'])]
    public function cancel(string $processId, Context $context): JsonResponse
    {
        $process = $this->loadProcess($processId, $context);
        if ($process === null) {
            return new JsonResponse(['message' => 'Process not found'], Response::HTTP_NOT_FOUND);
        }

        $this->processRepository->update([[
            'id' => $processId,
            'status' => ProcessDefinition::STATUS_CANCELLED,
        ]], $context);

        return new JsonResponse(['status' => ProcessDefinition::STATUS_CANCELLED]);
    }

    private function loadProcess(string $processId, Context $context): ?ProcessEntity
    {
        if (!Uuid::isValid($processId)) {
            return null;
        }

        $criteria = new Criteria([$processId]);
        $criteria->addAssociation('template');
        $criteria->addAssociation('categoryTemplate');
        $criteria->addAssociation('sources');

        /** @var ProcessEntity|null $process */
        $process = $this->processRepository->search($criteria, $context)->first();

        return $process;
    }
}
