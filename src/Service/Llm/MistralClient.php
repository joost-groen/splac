<?php declare(strict_types=1);

namespace Splac\Service\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class MistralClient implements LlmClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'mistral';
    }

    public function complete(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/chat/completions', [
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
            throw new LlmException('Mistral request failed: ' . $e->getMessage(), 0, $e);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content) || $content === '') {
            throw new LlmException('Mistral returned an empty response');
        }

        return $content;
    }

    public function ocrPdf(string $apiKey, string $model, string $pdfContent, string $filename): string
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/ocr', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'mistral-ocr-latest',
                    'document' => [
                        'type' => 'document_url',
                        'document_url' => 'data:application/pdf;base64,' . base64_encode($pdfContent),
                    ],
                    'table_format' => 'markdown',
                ],
                'timeout' => 180,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new LlmException('Mistral PDF OCR request failed: ' . $e->getMessage(), 0, $e);
        }

        $parts = [];
        foreach ($data['pages'] ?? [] as $page) {
            if (!\is_array($page)) {
                continue;
            }

            $markdown = trim(\is_string($page['markdown'] ?? null) ? $page['markdown'] : '');
            if ($markdown === '') {
                continue;
            }

            $index = \is_int($page['index'] ?? null) ? $page['index'] + 1 : \count($parts) + 1;
            $parts[] = \sprintf("=== OCR page %d ===\n%s", $index, $markdown);
        }

        $text = trim(implode("\n\n", $parts));
        if ($text === '') {
            throw new LlmException('Mistral returned an empty PDF OCR response');
        }

        return $text;
    }
}
