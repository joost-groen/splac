<?php declare(strict_types=1);

namespace Splac\MessageQueue\Handler;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Splac\Core\Content\Process\ProcessDefinition;
use Splac\Core\Content\Process\ProcessEntity;
use Splac\MessageQueue\Message\GenerateProcessMessage;
use Splac\Service\Llm\LlmAdaptiveThinkingRequiredException;
use Splac\Service\Llm\LlmBatchPendingException;
use Splac\Service\ProcessGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
class GenerateProcessHandler
{
    public function __construct(
        private readonly EntityRepository $processRepository,
        private readonly ProcessGenerator $processGenerator,
        private readonly MessageBusInterface $messageBus,
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
            ? array_merge([$message->onlyStep], $message->remainingSteps)
            : $this->processGenerator->resolveSteps($process);

        $this->processRepository->update([[
            'id' => $process->getId(),
            'status' => ProcessDefinition::STATUS_GENERATING,
            'errorMessage' => null,
        ]], $context);

        foreach ($steps as $index => $step) {
            $this->processRepository->update([[
                'id' => $process->getId(),
                'currentStep' => $step,
            ]], $context);

            try {
                $this->processGenerator->runStep(
                    $process,
                    $step,
                    $context,
                    $index === 0 ? $message->batchId : null,
                    $index === 0 && $message->forceAdaptiveThinking,
                );
            } catch (LlmBatchPendingException $e) {
                $this->messageBus->dispatch(
                    new GenerateProcessMessage(
                        $process->getId(),
                        $step,
                        $e->batchId,
                        array_values(array_slice($steps, $index + 1)),
                        $message->forceAdaptiveThinking,
                    ),
                    [new DelayStamp($e->retryAfterMilliseconds)]
                );

                return;
            } catch (LlmAdaptiveThinkingRequiredException) {
                $this->messageBus->dispatch(new GenerateProcessMessage(
                    $process->getId(),
                    $step,
                    null,
                    array_values(array_slice($steps, $index + 1)),
                    true,
                ));

                return;
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
