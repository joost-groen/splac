<?php declare(strict_types=1);

namespace Splac\Service\Llm;

final readonly class CompletionOptions
{
    public const REASONING_LOW = 'low';
    public const REASONING_MEDIUM = 'medium';
    public const REASONING_HIGH = 'high';

    private const REASONING_LEVELS = [
        self::REASONING_LOW,
        self::REASONING_MEDIUM,
        self::REASONING_HIGH,
    ];

    public function __construct(
        public bool $reasoningEnabled = false,
        public string $reasoningLevel = self::REASONING_MEDIUM,
        public bool $batchProcessing = false,
        public ?string $batchId = null,
        public bool $forceAdaptiveThinking = false,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromProcessInput(
        array $input,
        ?string $batchId = null,
        bool $forceAdaptiveThinking = false,
    ): self
    {
        $reasoningLevel = \is_string($input['reasoningLevel'] ?? null)
            ? $input['reasoningLevel']
            : self::REASONING_MEDIUM;

        if (!\in_array($reasoningLevel, self::REASONING_LEVELS, true)) {
            $reasoningLevel = self::REASONING_MEDIUM;
        }

        return new self(
            ($input['reasoningEnabled'] ?? false) === true,
            $reasoningLevel,
            ($input['batchProcessing'] ?? false) === true,
            $batchId,
            $forceAdaptiveThinking,
        );
    }

    public function withoutUnsupportedFeatures(LlmClientInterface $client): self
    {
        return new self(
            $this->reasoningEnabled && $client->supportsReasoning(),
            $this->reasoningLevel,
            $this->batchProcessing && $client->supportsBatchProcessing(),
            $client->supportsBatchProcessing() ? $this->batchId : null,
            $this->forceAdaptiveThinking,
        );
    }

    public function withForcedAdaptiveThinking(): self
    {
        return new self(
            $this->reasoningEnabled,
            $this->reasoningLevel,
            $this->batchProcessing,
            $this->batchId,
            true,
        );
    }
}
