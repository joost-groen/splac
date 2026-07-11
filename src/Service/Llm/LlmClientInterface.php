<?php declare(strict_types=1);

namespace Splac\Service\Llm;

interface LlmClientInterface
{
    /**
     * Provider key as used in the plugin configuration (e.g. "openai").
     */
    public function getName(): string;

    /**
     * Sends a chat completion request and returns the raw text answer.
     * Implementations should request JSON output from the provider where supported.
     *
     * @throws LlmException
     */
    public function complete(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string;
}
