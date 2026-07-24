<?php declare(strict_types=1);

namespace Splac\Service\Llm;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Facade around the configured LLM provider. Resolves provider, API key and
 * model from the plugin configuration and returns parsed JSON responses.
 */
class LlmService
{
    private const CONFIG_PREFIX = 'Splac.config.';

    private const MAX_OCR_CHARS = 60000;

    /**
     * @var array<string, LlmClientInterface>
     */
    private array $clients = [];

    /**
     * @param iterable<LlmClientInterface> $clients
     */
    public function __construct(
        private readonly SystemConfigService $systemConfig,
        iterable $clients,
        private readonly LlmUsageService $usageService,
    ) {
        foreach ($clients as $client) {
            $this->clients[$client->getName()] = $client;
        }
    }

    /**
     * Sends a prompt to the configured provider and returns the decoded JSON object.
     *
     * @return array<string, mixed>
     */
    public function completeJson(
        string $systemPrompt,
        string $userPrompt,
        ?string $processId = null,
        string $operation = 'generation',
    ): array
    {
        [$client, $apiKey, $model, $provider] = $this->configuredClient();

        $response = $client->complete($apiKey, $model, $systemPrompt, $userPrompt);
        $this->usageService->record($processId, $provider, $model, $operation, $response);

        return $this->decodeJson($response->text);
    }

    /**
     * Sends raw PDF bytes to the configured provider for OCR/document transcription.
     */
    public function ocrPdf(string $pdfContent, string $filename, ?string $processId = null): string
    {
        if ($pdfContent === '') {
            throw new LlmException('Cannot OCR an empty PDF');
        }

        [$client, $apiKey, $model, $provider] = $this->configuredClient();

        $response = $client->ocrPdf($apiKey, $model, $pdfContent, $filename);
        $this->usageService->record($processId, $provider, $model, 'ocr', $response);

        $text = trim($response->text);
        if (mb_strlen($text) > self::MAX_OCR_CHARS) {
            return mb_substr($text, 0, self::MAX_OCR_CHARS) . "\n[... OCR truncated ...]";
        }

        return $text;
    }

    /**
     * @return array{LlmClientInterface, string, string, string}
     */
    private function configuredClient(): array
    {
        $provider = (string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'provider') ?? 'openai');

        $client = $this->clients[$provider] ?? null;
        if ($client === null) {
            throw new LlmException(\sprintf('Unknown LLM provider "%s"', $provider));
        }

        $apiKey = (string) ($this->systemConfig->get(self::CONFIG_PREFIX . $provider . 'ApiKey') ?? '');
        $model = (string) ($this->systemConfig->get(self::CONFIG_PREFIX . $provider . 'Model') ?? '');

        if ($apiKey === '') {
            throw new LlmException(\sprintf('No API key configured for provider "%s". Please set it in the plugin configuration.', $provider));
        }
        if ($model === '') {
            throw new LlmException(\sprintf('No model configured for provider "%s". Please set it in the plugin configuration.', $provider));
        }

        return [$client, $apiKey, $model, $provider];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        $text = trim($raw);

        // Some providers wrap JSON in markdown code fences despite JSON mode.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/```\s*$/', '', $text) ?? $text;
            $text = trim($text);
        }

        // Fall back to the outermost JSON object if extra prose surrounds it.
        if (!str_starts_with($text, '{')) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $text = substr($text, $start, $end - $start + 1);
            }
        }

        try {
            $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException('LLM did not return valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!\is_array($decoded)) {
            throw new LlmException('LLM did not return a JSON object');
        }

        return $decoded;
    }
}
