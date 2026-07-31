<?php declare(strict_types=1);

namespace Splac\Service\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicClient implements LlmClientInterface
{
    private const API_URL = 'https://api.anthropic.com/v1';

    private const BATCH_CUSTOM_ID = 'splac-completion';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function supportsReasoning(): bool
    {
        return true;
    }

    public function supportsBatchProcessing(): bool
    {
        return true;
    }

    public function complete(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        CompletionOptions $options,
        ?string $cacheableContext = null,
    ): LlmResponse
    {
        $payload = $this->messagePayload($model, $systemPrompt, $userPrompt, $options, $cacheableContext);

        if ($options->batchProcessing) {
            return $this->completeBatch($apiKey, $payload, $options->batchId);
        }

        try {
            [$statusCode, $responseData] = $this->sendMessageRequest($apiKey, $payload);

            if (
                $options->reasoningEnabled
                && !$options->forceAdaptiveThinking
                && $this->isManualThinkingUnsupportedResponse($statusCode, $responseData)
            ) {
                $payload = $this->messagePayload(
                    $model,
                    $systemPrompt,
                    $userPrompt,
                    $options->withForcedAdaptiveThinking(),
                    $cacheableContext,
                );
                [$statusCode, $responseData] = $this->sendMessageRequest($apiKey, $payload);
            }

            $data = $this->responseArray($statusCode, $responseData, 'request');
        } catch (\Throwable $e) {
            if ($e instanceof LlmException) {
                throw $e;
            }

            throw new LlmException('Anthropic request failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->messageResponse($data);
    }

    public function ocrPdf(string $apiKey, string $model, string $pdfContent, string $filename): LlmResponse
    {
        try {
            $response = $this->httpClient->request('POST', self::API_URL . '/messages', [
                'headers' => $this->headers($apiKey),
                'json' => [
                    'model' => $model,
                    'max_tokens' => 16384,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'document',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'application/pdf',
                                    'data' => base64_encode($pdfContent),
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => self::PDF_OCR_PROMPT,
                            ],
                        ],
                    ]],
                ],
                'timeout' => 180,
            ]);

            $data = $this->responseArray($response->getStatusCode(), $response->toArray(false), 'PDF OCR request');
        } catch (\Throwable $e) {
            if ($e instanceof LlmException) {
                throw $e;
            }

            throw new LlmException('Anthropic PDF OCR request failed: ' . $e->getMessage(), 0, $e);
        }

        $parts = [];
        foreach ($data['content'] ?? [] as $content) {
            if (\is_array($content) && ($content['type'] ?? null) === 'text' && \is_string($content['text'] ?? null)) {
                $parts[] = $content['text'];
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new LlmException('Anthropic returned an empty PDF OCR response');
        }

        return new LlmResponse(
            $text,
            $this->usageValue($data, 'input_tokens'),
            $this->usageValue($data, 'output_tokens'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        CompletionOptions $options,
        ?string $cacheableContext,
    ): array {
        $payload = [
            'model' => $model,
            'max_tokens' => 8192,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $this->messageContent($userPrompt, $cacheableContext)],
            ],
        ];

        if (!$options->reasoningEnabled) {
            return $payload;
        }

        if ($options->forceAdaptiveThinking || $this->usesAdaptiveThinking($model)) {
            $payload['thinking'] = ['type' => 'adaptive'];
            $payload['output_config'] = ['effort' => $options->reasoningLevel];
            $payload['max_tokens'] = $options->reasoningLevel === CompletionOptions::REASONING_HIGH
                ? 32768
                : 16384;

            return $payload;
        }

        $budget = match ($options->reasoningLevel) {
            CompletionOptions::REASONING_LOW => 2048,
            CompletionOptions::REASONING_HIGH => 16384,
            default => 8192,
        };

        $payload['thinking'] = [
            'type' => 'enabled',
            'budget_tokens' => $budget,
        ];
        $payload['max_tokens'] = max(8192, $budget + 8192);

        return $payload;
    }

    /**
     * @return string|list<array<string, mixed>>
     */
    private function messageContent(string $userPrompt, ?string $cacheableContext): string|array
    {
        if ($cacheableContext === null || trim($cacheableContext) === '') {
            return $userPrompt;
        }

        return [
            [
                'type' => 'text',
                'text' => "SOURCE DOCUMENTS:\n{$cacheableContext}",
                'cache_control' => [
                    'type' => 'ephemeral',
                    'ttl' => '5m',
                ],
            ],
            [
                'type' => 'text',
                'text' => "TASK INSTRUCTIONS:\n{$userPrompt}",
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function completeBatch(string $apiKey, array $payload, ?string $batchId): LlmResponse
    {
        if ($batchId === null || $batchId === '') {
            return $this->createBatch($apiKey, $payload);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                self::API_URL . '/messages/batches/' . rawurlencode($batchId),
                [
                    'headers' => $this->headers($apiKey),
                    'timeout' => 30,
                ]
            );
            $batch = $this->responseArray($response->getStatusCode(), $response->toArray(false), 'batch status request');
        } catch (\Throwable $e) {
            if ($e instanceof LlmException) {
                throw $e;
            }

            throw new LlmException('Anthropic batch status request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = \is_string($batch['processing_status'] ?? null) ? $batch['processing_status'] : '';
        if ($status === 'in_progress') {
            throw new LlmBatchPendingException($batchId);
        }
        if ($status !== 'ended') {
            throw new LlmException(\sprintf(
                'Anthropic batch "%s" returned unexpected status "%s"',
                $batchId,
                $status !== '' ? $status : 'unknown'
            ));
        }

        return $this->batchResult($apiKey, $batchId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createBatch(string $apiKey, array $payload): LlmResponse
    {
        try {
            $response = $this->httpClient->request('POST', self::API_URL . '/messages/batches', [
                'headers' => $this->headers($apiKey),
                'json' => [
                    'requests' => [[
                        'custom_id' => self::BATCH_CUSTOM_ID,
                        'params' => $payload,
                    ]],
                ],
                'timeout' => 30,
            ]);
            $batch = $this->responseArray($response->getStatusCode(), $response->toArray(false), 'batch creation request');
        } catch (\Throwable $e) {
            if ($e instanceof LlmException) {
                throw $e;
            }

            throw new LlmException('Anthropic batch creation failed: ' . $e->getMessage(), 0, $e);
        }

        $batchId = $batch['id'] ?? null;
        if (!\is_string($batchId) || $batchId === '') {
            throw new LlmException('Anthropic batch creation returned no batch ID');
        }

        throw new LlmBatchPendingException($batchId);
    }

    private function batchResult(string $apiKey, string $batchId): LlmResponse
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                self::API_URL . '/messages/batches/' . rawurlencode($batchId) . '/results',
                [
                    'headers' => $this->headers($apiKey),
                    'timeout' => 60,
                ]
            );
            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new LlmException('Anthropic batch result download failed: ' . $e->getMessage(), 0, $e);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new LlmException(\sprintf(
                'Anthropic batch result download failed (HTTP %d): %s',
                $statusCode,
                $this->errorFromBody($body)
            ));
        }

        foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            try {
                $result = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new LlmException('Anthropic batch returned invalid JSONL: ' . $e->getMessage(), 0, $e);
            }

            if (!\is_array($result) || ($result['custom_id'] ?? null) !== self::BATCH_CUSTOM_ID) {
                continue;
            }

            $resultData = \is_array($result['result'] ?? null) ? $result['result'] : [];
            if (($resultData['type'] ?? null) !== 'succeeded' || !\is_array($resultData['message'] ?? null)) {
                $error = \is_array($resultData['error'] ?? null) ? $resultData['error'] : [];
                $message = \is_string($error['message'] ?? null) ? $error['message'] : 'request did not succeed';

                if ($this->isManualThinkingUnsupportedMessage($message)) {
                    throw new LlmAdaptiveThinkingRequiredException($message);
                }

                throw new LlmException('Anthropic batch request failed: ' . $message);
            }

            return $this->messageResponse($resultData['message']);
        }

        throw new LlmException('Anthropic batch result did not contain the expected request');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function messageResponse(array $data): LlmResponse
    {
        $parts = [];
        foreach ($data['content'] ?? [] as $block) {
            if (!\is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text = $block['text'] ?? null;
            if (\is_string($text) && $text !== '') {
                $parts[] = $text;
            }
        }

        $content = trim(implode('', $parts));
        if ($content === '') {
            $stopReason = \is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : 'unknown';
            throw new LlmException(\sprintf(
                'Anthropic returned no final text content (stop reason: %s)',
                $stopReason
            ));
        }

        return new LlmResponse(
            text: $content,
            inputTokens: $this->usageValue($data, 'input_tokens'),
            outputTokens: $this->usageValue($data, 'output_tokens'),
            cacheCreationInputTokens: $this->usageValue($data, 'cache_creation_input_tokens'),
            cacheReadInputTokens: $this->usageValue($data, 'cache_read_input_tokens'),
        );
    }

    /**
     * Claude 4.5 and older models use a manual thinking token budget. Newer
     * models use adaptive thinking plus output_config.effort.
     */
    private function usesAdaptiveThinking(string $model): bool
    {
        if (preg_match('/claude-(?:opus|sonnet|haiku)-(\d+)(?:-(\d+))?/i', $model, $matches) === 1) {
            $major = (int) $matches[1];
            $minor = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;

            return $major > 4 || ($major === 4 && $minor >= 6);
        }

        if (preg_match('/claude-(\d+)-(\d+)-(?:opus|sonnet|haiku)/i', $model, $matches) === 1) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];

            return $major > 4 || ($major === 4 && $minor >= 6);
        }

        if (preg_match('/claude-3(?:-|$)/i', $model) === 1) {
            return false;
        }

        // Prefer the current API mode for aliases whose version is not encoded
        // in their name. An explicit Anthropic rejection still triggers a retry.
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{int, array<string, mixed>}
     */
    private function sendMessageRequest(string $apiKey, array $payload): array
    {
        $response = $this->httpClient->request('POST', self::API_URL . '/messages', [
            'headers' => $this->headers($apiKey),
            'json' => $payload,
            'timeout' => 120,
        ]);

        return [$response->getStatusCode(), $response->toArray(false)];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isManualThinkingUnsupportedResponse(int $statusCode, array $data): bool
    {
        if ($statusCode !== 400) {
            return false;
        }

        $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = \is_string($error['message'] ?? null) ? $error['message'] : '';

        return $this->isManualThinkingUnsupportedMessage($message);
    }

    private function isManualThinkingUnsupportedMessage(string $message): bool
    {
        return str_contains($message, 'thinking.type.enabled')
            && str_contains(strtolower($message), 'not supported');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function responseArray(int $statusCode, array $data, string $operation): array
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return $data;
        }

        $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = \is_string($error['message'] ?? null) ? $error['message'] : 'Unknown API error';

        throw new LlmException(\sprintf(
            'Anthropic %s failed (HTTP %d): %s',
            $operation,
            $statusCode,
            $message
        ));
    }

    private function errorFromBody(string $body): string
    {
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return trim($body) !== '' ? mb_substr(trim($body), 0, 500) : 'Unknown API error';
        }

        $error = \is_array($data['error'] ?? null) ? $data['error'] : [];

        return \is_string($error['message'] ?? null) ? $error['message'] : 'Unknown API error';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function usageValue(array $data, string $key): int
    {
        $value = $data['usage'][$key] ?? 0;

        return \is_int($value) ? max(0, $value) : 0;
    }
}
