<?php declare(strict_types=1);

namespace Splac\Service\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiClient implements LlmClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function supportsReasoning(): bool
    {
        return false;
    }

    public function supportsBatchProcessing(): bool
    {
        return false;
    }

    public function complete(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        CompletionOptions $options,
    ): LlmResponse
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.2,
                ],
                'timeout' => 120,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new LlmException('OpenAI request failed: ' . $e->getMessage(), 0, $e);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content) || $content === '') {
            throw new LlmException('OpenAI returned an empty response');
        }

        return new LlmResponse(
            $content,
            $this->usageValue($data, 'prompt_tokens'),
            $this->usageValue($data, 'completion_tokens'),
        );
    }

    public function ocrPdf(string $apiKey, string $model, string $pdfContent, string $filename): LlmResponse
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'input' => [[
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_file',
                                'filename' => $this->normalizePdfFilename($filename),
                                'file_data' => 'data:application/pdf;base64,' . base64_encode($pdfContent),
                                'detail' => 'high',
                            ],
                            [
                                'type' => 'input_text',
                                'text' => self::PDF_OCR_PROMPT,
                            ],
                        ],
                    ]],
                    'max_output_tokens' => 16384,
                    'store' => false,
                ],
                'timeout' => 180,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new LlmException('OpenAI PDF OCR request failed: ' . $e->getMessage(), 0, $e);
        }

        $parts = [];
        foreach ($data['output'] ?? [] as $output) {
            if (!\is_array($output) || ($output['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($output['content'] ?? [] as $content) {
                if (\is_array($content) && ($content['type'] ?? null) === 'output_text' && \is_string($content['text'] ?? null)) {
                    $parts[] = $content['text'];
                }
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new LlmException('OpenAI returned an empty PDF OCR response');
        }

        return new LlmResponse(
            $text,
            $this->usageValue($data, 'input_tokens'),
            $this->usageValue($data, 'output_tokens'),
        );
    }

    private function normalizePdfFilename(string $filename): string
    {
        $filename = trim(basename($filename));
        if ($filename === '') {
            return 'document.pdf';
        }

        return str_ends_with(strtolower($filename), '.pdf') ? $filename : $filename . '.pdf';
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
