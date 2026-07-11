<?php declare(strict_types=1);

namespace Splac\MessageQueue\Handler;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Splac\Core\Content\Process\ProcessDefinition;
use Splac\Core\Content\Process\ProcessEntity;
use Splac\MessageQueue\Message\GenerateProcessMessage;
use Splac\Service\ProcessGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateProcessHandler
{
    public function __construct(
        private readonly EntityRepository $processRepository,
        private readonly ProcessGenerator $processGenerator,
    ) {
    }

    public function __invoke(GenerateProcessMessage $message): void
    {
        $context = Context::createDefaultContext();

        $process = $this->loadProcess($message->processId, $context);
        if ($process === null || $process->getStatus() === ProcessDefinition::STATUS_CANCELLED) {
            return;
        }

        $steps = $message->onlyStep !== null
            ? [$message->onlyStep]
            : $this->processGenerator->resolveSteps($process);

        $this->processRepository->update([[
            'id' => $process->getId(),
            'status' => ProcessDefinition::STATUS_GENERATING,
            'errorMessage' => null,
        ]], $context);

        foreach ($steps as $step) {
            $this->processRepository->update([[
                'id' => $process->getId(),
                'currentStep' => $step,
            ]], $context);

            try {
                $this->processGenerator->runStep($process, $step, $context);
            } catch (\Throwable $e) {
                $this->processRepository->update([[
                    'id' => $process->getId(),
                    'status' => ProcessDefinition::STATUS_FAILED,
                    'errorMessage' => \sprintf('Step "%s" failed: %s', $step, $e->getMessage()),
                ]], $context);

                return;
            }

            // Reload so a cancellation during a long-running step is honored.
            $current = $this->loadProcess($process->getId(), $context);
            if ($current === null || $current->getStatus() === ProcessDefinition::STATUS_CANCELLED) {
                return;
            }
        }

        $this->processRepository->update([[
            'id' => $process->getId(),
            'status' => ProcessDefinition::STATUS_REVIEW,
            'currentStep' => null,
        ]], $context);
    }

    private function loadProcess(string $processId, Context $context): ?ProcessEntity
    {
        $criteria = new Criteria([$processId]);
        $criteria->addAssociation('template');
        $criteria->addAssociation('categoryTemplate');
        $criteria->addAssociation('sources');

        /** @var ProcessEntity|null $process */
        $process = $this->processRepository->search($criteria, $context)->first();

        return $process;
    }
}
